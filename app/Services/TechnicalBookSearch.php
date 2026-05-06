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

        // Auto-detect do domínio quando o caller não força um.
        // 1.312 chunks soldadura vs 424 naval — sem filter, queries naval
        // ("estabilidade do navio") são dominadas por chunks de soldadura
        // por volume. O auto-detect olha para palavras-chave inequívocas
        // do query e força o filter no domínio certo.
        if ($domain === null) {
            $domain = $this->autoDetectDomain($query);
        }

        // 1) Semantic search via pgvector (se disponível).
        //    Cosine distance via operador <=> (pgvector). Score baixo
        //    = mais semelhante. ORDER BY embedding <=> query_vector ASC.
        //    Cai para keyword search se NVIDIA não responder ou se
        //    chunks ainda não têm embedding gerado.
        $semantic = $this->semanticSearch($query, $limit, $domain);
        if (!empty($semantic)) return $semantic;

        // 2) Fallback keyword (ILIKE OR'd por palavra) — robusto para
        //    códigos alfa-numéricos (E7018, P/N 12345). Mantido para
        //    quando o pgvector não está disponível ou para queries
        //    técnicas onde o exact-match supera semantics.
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
        $candidates = $q->limit(20)->get(['book_key', 'book_title', 'page_no', 'content', 'domain'])->all();

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
                'book_key'   => (string) ($r->book_key   ?? ''),
                'book_title' => (string) ($r->book_title ?? ''),
                'page_no'    => (int)    ($r->page_no    ?? 0),
                'domain'     => (string) ($r->domain     ?? ''),
                'snippet'    => $this->extractSnippet($content, $query, 280),
            ];
        }, $rows);
    }

    /**
     * Detecta automaticamente o domínio da query com base em palavras-chave
     * inequívocas. Devolve 'naval', 'soldadura' ou null (query mista/genérica
     * → não filtra, fallback ao ranking semântico puro).
     *
     * Heurística:
     *   • Conta hits de keywords navais vs soldadura
     *   • Se uma classe domina por 2x ou mais → força esse domínio
     *   • Empate ou ambos zero → null (sem filtro)
     */
    private function autoDetectDomain(string $query): ?string
    {
        $lower = mb_strtolower($query);

        $navalKeywords = [
            'navio','vessel','casco','hull','estabilidade','displacement','deslocamento',
            'plimsoll','load line','calado','draft','convés','deck','propulsor','propeller',
            'leme','rudder','arquitectura naval','naval architecture','imo','solas','marpol',
            'iacs','dnv','lloyd','class society','classification','tonelagem','ton',
            'estaleiro','shipyard','drydock','dique','launching','dock',
            'roll','pitch','yaw','metacentro','metacentric','flutuação','buoyancy',
        ];

        $soldaduraKeywords = [
            'soldadura','soldagem','welding','weld','solda','wps','pqr','aws','iso 15614','asme ix',
            'mma','smaw','tig','gtaw','mig','mag','gmaw','fcaw','saw','plasma',
            'preheat','pré-aquecimento','pre aquecimento','pwht','interpass','metal de adição',
            'electrode','eléctrodo','consumível','filler metal',
            'e6013','e7018','e7016','e308l','er70s','er316l',
            'cordão','cord','bead','passe','pass','junta soldada','weld joint',
            'inclusão','porosidade','fissura térmica','crack','trinca','heat affected',
            'metalurgia','metallurgy','austenita','ferrita','martensita',
        ];

        $navalScore     = 0;
        $soldaduraScore = 0;
        foreach ($navalKeywords as $kw)     { if (str_contains($lower, $kw)) $navalScore++; }
        foreach ($soldaduraKeywords as $kw) { if (str_contains($lower, $kw)) $soldaduraScore++; }

        // Domínio forte: 2x mais hits do que o outro (e pelo menos 1)
        if ($navalScore >= 1 && $navalScore >= 2 * max(1, $soldaduraScore)) {
            return 'naval';
        }
        if ($soldaduraScore >= 1 && $soldaduraScore >= 2 * max(1, $navalScore)) {
            return 'soldadura';
        }

        return null;
    }

    /**
     * Pesquisa vectorial via pgvector (cosine similarity).
     * Usa NVIDIA NIM nv-embedqa-e5-v5 (1024 dims) para gerar o embedding
     * da query e ordena por proximidade semântica.
     *
     * Devolve [] em qualquer falha (NVIDIA down, chunks sem embedding,
     * pgvector inactivo) → caller cai no keyword fallback.
     */
    private function semanticSearch(string $query, int $limit, ?string $domain): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') return [];

            // Verificar se há chunks com embedding (evita query desnecessária)
            $hasEmbeddings = DB::selectOne(
                'SELECT EXISTS(SELECT 1 FROM technical_book_chunks WHERE embedding IS NOT NULL LIMIT 1) as has'
            );
            if (!($hasEmbeddings->has ?? false)) return [];

            $emb = app(\App\Services\EmbeddingService::class);
            if (!$emb->isAvailable()) return [];

            $vec = $emb->embed($query, 'query');
            if (!$vec || count($vec) === 0) return [];

            $literal = '[' . implode(',', array_map(fn($f) => sprintf('%.6f', $f), $vec)) . ']';

            $sql = "SELECT book_key, book_title, page_no, content, domain,
                           1 - (embedding <=> ?::vector) AS similarity
                    FROM technical_book_chunks
                    WHERE embedding IS NOT NULL
                    " . ($domain ? "AND domain = ?" : "") . "
                    ORDER BY embedding <=> ?::vector ASC
                    LIMIT ?";

            $bindings = $domain
                ? [$literal, $domain, $literal, $limit]
                : [$literal, $literal, $limit];

            $rows = DB::select($sql, $bindings);

            return array_map(fn($r) => [
                'book_key'   => (string) $r->book_key,
                'book_title' => (string) $r->book_title,
                'page_no'    => (int) $r->page_no,
                'domain'     => (string) $r->domain,
                'snippet'    => $this->extractSnippet((string) $r->content, $query, 280),
                'similarity' => round((float) $r->similarity, 3),
            ], $rows);
        } catch (\Throwable $e) {
            \Log::warning('TechnicalBookSearch: semantic failed → keyword fallback', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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
            // Token machine-readable [BOOK:key:page] permite ao frontend
            // converter automaticamente em link clicável que abre o PDF
            // na página exacta.
            $token = '[BOOK:' . ($hit['book_key'] ?? '') . ':' . ($hit['page_no'] ?? 0) . ']';
            $lines[] = sprintf(
                "\n**%s** · p.%d · _%s_ %s\n> %s",
                $hit['book_title'],
                $hit['page_no'],
                $hit['domain'],
                $token,
                $hit['snippet']
            );
        }
        $lines[] = "\n**Citação:** ao referir um destes trechos no teu output, INCLUI o token machine-readable `[BOOK:key:page]` exactamente como aparece acima — o frontend converte-o em link clicável que abre o PDF na página certa. Exemplo: `Segundo Modenesi (p.87) [BOOK:01-metalurgia-da-soldagem-modenesi-marques-santos:87], o pré-aquecimento...`";
        return implode("\n", $lines);
    }
}
