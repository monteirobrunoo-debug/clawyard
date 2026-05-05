<?php

namespace App\Services;

use App\Models\TechnicalBookChunk;
use Illuminate\Support\Facades\DB;

/**
 * Pesquisa keyword-based sobre os ~15 livros técnicos PartYard
 * (soldadura naval + naval + outros). Não é full RAG (não há
 * embeddings) mas para um corpus de ~3000 páginas e queries com
 * termos técnicos específicos (E7018, MIG, PWHT, MTU 16V4000),
 * keyword matching é fast e suficiente.
 *
 * Chamado por WorkReportAgent::augmentWithBooks() para injectar
 * trechos relevantes + citações de página no system prompt.
 */
class TechnicalBookSearch
{
    /**
     * Devolve até `$limit` chunks ordenados por relevância para
     * a query. Cada chunk inclui book_title + page_no para citação.
     *
     * @return array<array{book_title:string, page_no:int, snippet:string, domain:string}>
     */
    public function search(string $query, int $limit = 4, ?string $domain = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) return [];

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Postgres: usa to_tsvector + ts_rank para ranking real
            $sql = "SELECT book_title, page_no, content, domain,
                           ts_rank(to_tsvector('portuguese', content),
                                   plainto_tsquery('portuguese', ?)) AS rank
                    FROM technical_book_chunks
                    WHERE to_tsvector('portuguese', content) @@ plainto_tsquery('portuguese', ?)
                    " . ($domain ? "AND domain = ?" : "") . "
                    ORDER BY rank DESC
                    LIMIT ?";
            $bindings = $domain
                ? [$query, $query, $domain, $limit]
                : [$query, $query, $limit];
            $rows = DB::select($sql, $bindings);
        } else {
            // SQLite/MySQL fallback: LIKE simples
            $q = TechnicalBookChunk::query()
                ->where('content', 'LIKE', '%' . $query . '%');
            if ($domain) $q->where('domain', $domain);
            $rows = $q->limit($limit)->get(['book_title', 'page_no', 'content', 'domain'])->all();
        }

        return array_map(function ($r) use ($query) {
            $content = (string) ($r->content ?? '');
            return [
                'book_title' => (string) ($r->book_title ?? ''),
                'page_no'    => (int)    ($r->page_no    ?? 0),
                'domain'     => (string) ($r->domain     ?? ''),
                'snippet'    => $this->extractSnippet($content, $query, 280),
            ];
        }, $rows);
    }

    /**
     * Extrai um trecho ~280 chars centrado na primeira ocorrência
     * da query (case-insensitive). Para keywords técnicas é onde o
     * contexto vive — começo da página costuma ser índice.
     */
    private function extractSnippet(string $content, string $query, int $maxLen): string
    {
        $pos = mb_stripos($content, $query);
        if ($pos === false) {
            return mb_substr($content, 0, $maxLen) . '...';
        }
        $start = max(0, $pos - 80);
        $excerpt = mb_substr($content, $start, $maxLen);
        return ($start > 0 ? '...' : '') . $excerpt . (mb_strlen($content) > $start + $maxLen ? '...' : '');
    }

    /**
     * Conveniência para o WorkReportAgent: devolve um bloco
     * formatado em markdown pronto a injectar no system prompt.
     */
    public function buildContextBlock(string $query, int $limit = 4, ?string $domain = null): string
    {
        $hits = $this->search($query, $limit, $domain);
        if (empty($hits)) return '';

        $lines = ['## 📚 BIBLIOTECA TÉCNICA PARTYARD — trechos relevantes para a tua resposta:'];
        foreach ($hits as $hit) {
            $lines[] = sprintf(
                "\n**%s** · p.%d · _%s_\n> %s",
                $hit['book_title'],
                $hit['page_no'],
                $hit['domain'],
                $hit['snippet']
            );
        }
        $lines[] = "\n_Cita SEMPRE a fonte ao usar estes trechos: ex \"(Modenesi, p.87)\". Se nenhum trecho responder à pergunta exacta, di-lo._";
        return implode("\n", $lines);
    }
}
