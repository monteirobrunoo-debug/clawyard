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

        // Tokenizer simples — split por espaços + pontuação, mantém códigos
        // tipo E7018, P/N 1290, MTU-16V4000 (números + letras OK).
        // Filtra palavras curtas comuns ("de", "da", "do", "para", "que")
        // que iam matar a relevância do ranking por hit count.
        $stopWords = ['de','da','do','dos','das','para','que','com','em','na','no','os','as',
                      'um','uma','e','ou','a','o','é','se','ao','aos','for','of','the','and'];
        $words = preg_split('/[\s,;:.!?()"\'\/]+/u', mb_strtolower($query)) ?: [];
        $words = array_values(array_filter($words, function ($w) use ($stopWords) {
            return mb_strlen($w) >= 2 && !in_array($w, $stopWords, true);
        }));
        if (empty($words)) $words = [$query];

        $driver = DB::connection()->getDriverName();
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // Constrói query: ranking = nº de palavras-chave que fazem match
        // (case-insensitive). Mais palavras matched → maior score.
        $q = TechnicalBookChunk::query();
        $q->where(function ($w) use ($words, $likeOp) {
            foreach ($words as $word) {
                $w->orWhere('content', $likeOp, '%' . $word . '%');
            }
        });
        if ($domain) $q->where('domain', $domain);

        // Pull mais que limit (até 20) e re-rank em PHP por nº de matches.
        // Para 1.736 chunks é instantâneo; para 100k+ adicionar índice tsvector.
        $candidates = $q->limit(20)->get(['book_title', 'page_no', 'content', 'domain'])->all();

        // Ranking PHP: contagem de palavras únicas que aparecem no chunk
        usort($candidates, function ($a, $b) use ($words) {
            $score = function ($chunk) use ($words) {
                $low = mb_strtolower((string) $chunk->content);
                $hits = 0;
                foreach ($words as $w) {
                    if (str_contains($low, $w)) $hits++;
                }
                return $hits;
            };
            return $score($b) <=> $score($a);
        });

        $rows = array_slice($candidates, 0, $limit);

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
