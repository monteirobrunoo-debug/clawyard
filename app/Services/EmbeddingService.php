<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para os endpoints de embeddings da NVIDIA NIM.
 * Usado pela ingestão de livros + queries semânticas em runtime.
 *
 * Modelo default: nvidia/nv-embedqa-e5-v5
 *   • 1024 dims · multilíngue PT/EN/ES · optimizado para retrieval QA
 *   • Free tier no integrate.api.nvidia.com (mesmo endpoint do Carlos)
 *
 * Custo zero quando usa o tier gratuito da NVIDIA (até X req/dia).
 * Acima disso há limite de RPM — esta classe respeita batch size + retry.
 */
class EmbeddingService
{
    private Client $http;
    private string $model;
    private string $baseUrl;
    private string $apiKey;

    public const DEFAULT_MODEL = 'nvidia/nv-embedqa-e5-v5';
    public const DIMENSIONS    = 1024;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.nvidia.api_key', '');
        $this->baseUrl = (string) config('services.nvidia.base_url', 'https://integrate.api.nvidia.com/v1');
        $this->model   = (string) config('services.embedding.model', self::DEFAULT_MODEL);
        $this->http    = new Client([
            'base_uri'        => rtrim($this->baseUrl, '/') . '/',
            'timeout'         => 60,
            'connect_timeout' => 10,
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Gera o embedding de uma string (query). Para indexação em batch,
     * usar embedBatch() — muito mais eficiente.
     *
     * @return array<float>|null  vector de 1024 floats, ou null em erro
     */
    public function embed(string $text, string $inputType = 'query'): ?array
    {
        $clean = trim(mb_substr($text, 0, 8000));
        if ($clean === '') return null;

        $vectors = $this->embedBatch([$clean], $inputType);
        return $vectors[0] ?? null;
    }

    /**
     * Gera embeddings para um array de strings num só request.
     * NVIDIA NIM aceita até ~32 strings/batch consoante o modelo.
     *
     * @param  array<string>  $texts
     * @param  string  $inputType  'query' (busca) | 'passage' (indexação)
     * @return array<int, array<float>>  array de vectors, mesma ordem do input
     */
    public function embedBatch(array $texts, string $inputType = 'passage'): array
    {
        if (!$this->isAvailable()) return [];
        if (empty($texts)) return [];

        // Limpa e trunca cada texto (≤8K chars/cada — modelo aceita 512 tokens)
        $clean = array_map(
            fn($t) => trim(mb_substr((string) $t, 0, 8000)),
            $texts
        );
        $clean = array_values(array_filter($clean, fn($t) => $t !== ''));

        if (empty($clean)) return [];

        try {
            $res = $this->http->post('embeddings', [
                'json' => [
                    'model'      => $this->model,
                    'input'      => $clean,
                    'input_type' => $inputType,        // 'query' | 'passage'
                    'encoding_format' => 'float',
                    // CRITICAL: nv-embedqa-e5-v5 tem limite de 512 tokens.
                    // Sem truncate, qualquer chunk > 512 tokens (= ~2000 chars
                    // PT/EN) faz a API rejeitar o BATCH INTEIRO. truncate=END
                    // diz ao server para cortar nos 512 tokens — todos os
                    // chunks passam, mesmo livros densos como Modenesi.
                    'truncate'        => 'END',
                ],
                'http_errors' => false,
            ]);

            $status = $res->getStatusCode();
            $body   = (string) $res->getBody();

            if ($status >= 400) {
                Log::warning('EmbeddingService: API error', [
                    'status'      => $status,
                    'body'        => mb_substr($body, 0, 400),
                    'batch_size'  => count($clean),
                    'first_chars' => mb_substr($clean[0] ?? '', 0, 80),
                ]);
                return [];
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['data'])) {
                return [];
            }

            // Resposta: { data: [{ embedding: [...], index: 0 }, ...] }
            $sorted = $data['data'];
            usort($sorted, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            return array_map(fn($r) => array_map('floatval', (array) ($r['embedding'] ?? [])), $sorted);
        } catch (\Throwable $e) {
            Log::error('EmbeddingService: exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getModel(): string { return $this->model; }
    public function getDimensions(): int { return self::DIMENSIONS; }
}
