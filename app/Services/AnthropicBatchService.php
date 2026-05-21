<?php

namespace App\Services;

use App\Models\AnthropicBatch;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper para a Anthropic Message Batches API.
 *
 * Pedido directo do Bruno (2026-05-21): "Anthropic Batch API nightly
 * multi-agent (50% off) quero este vale a pena".
 *
 * Docs: https://docs.anthropic.com/en/api/messages-batches
 *
 * Mecânica:
 *   1. submit(model, kind, requests[]) — cria batch na Anthropic, devolve
 *      AnthropicBatch persistido com status='created'.
 *   2. poll(batch) — GET /v1/messages/batches/{id}, actualiza status.
 *   3. collectResults(batch) — descarrega o JSONL de results, devolve
 *      array de {custom_id, ok, text, usage, error}. Marca results_collected.
 *
 * O caller é responsável por:
 *   • Definir o custom_id de cada request (ex.: "tender:309:mildef")
 *   • Aplicar os results às rows originais (this service não sabe nada
 *     sobre tenders / suppliers)
 *
 * Custos: Batch API = 50% off vs Messages API standard, mesma input/
 * output token economy. SLA ≤24h, normalmente <1h.
 */
class AnthropicBatchService
{
    private Client $http;
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.key', '');
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'timeout'  => 60,
            'connect_timeout' => 10,
        ]);
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Submete um batch novo.
     *
     * @param string $model    Anthropic model id
     * @param string $kind     Categoria interna (tender-analysis|supplier-intel|...)
     * @param array  $requests Lista de requests, cada um:
     *   [
     *     'custom_id' => string,    // único no batch, ex.: "tender:309:mildef"
     *     'system'    => string,
     *     'messages'  => [['role'=>'user','content'=>string]],
     *     'max_tokens'=> int,
     *   ]
     * @param int|null $userId  Quem disparou (audit)
     *
     * @return AnthropicBatch|null  null se API rejeitar
     */
    public function submit(string $model, string $kind, array $requests, ?int $userId = null): ?AnthropicBatch
    {
        if (!$this->isAvailable()) {
            Log::warning('AnthropicBatch::submit aborted — no API key');
            return null;
        }
        if (empty($requests)) {
            Log::info('AnthropicBatch::submit called with empty requests, skipping');
            return null;
        }

        // Persist row em pending state primeiro para audit, mesmo que falhe.
        $row = AnthropicBatch::create([
            'model'             => $model,
            'kind'              => $kind,
            'request_count'     => count($requests),
            'status'            => AnthropicBatch::STATUS_PENDING,
            'created_by_user_id'=> $userId,
            'metadata'          => ['custom_ids' => array_column($requests, 'custom_id')],
        ]);

        // Anthropic batch request format: requests[].params has the same
        // shape as a normal Messages API call (model, system, messages,
        // max_tokens, etc.).
        $payload = [
            'requests' => array_map(function ($r) {
                return [
                    'custom_id' => (string) $r['custom_id'],
                    'params' => array_filter([
                        'model'      => $r['model']      ?? null,
                        'system'     => $r['system']     ?? null,
                        'messages'   => $r['messages']   ?? null,
                        'max_tokens' => $r['max_tokens'] ?? 1024,
                    ], fn ($v) => $v !== null),
                ];
            }, array_values($requests)),
        ];

        // Apply default model to entries that don't set one.
        foreach ($payload['requests'] as &$pr) {
            if (!isset($pr['params']['model'])) {
                $pr['params']['model'] = $model;
            }
        }
        unset($pr);

        try {
            $res = $this->http->post('v1/messages/batches', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);
            $status = $res->getStatusCode();
            $body   = (string) $res->getBody();
        } catch (\Throwable $e) {
            Log::error('AnthropicBatch::submit transport failed', [
                'batch_row' => $row->id,
                'error'     => $e->getMessage(),
            ]);
            $row->update(['status' => AnthropicBatch::STATUS_FAILED, 'metadata' => array_merge((array) $row->metadata, ['error' => $e->getMessage()])]);
            return $row;
        }

        if ($status < 200 || $status >= 300) {
            Log::warning('AnthropicBatch::submit rejected', [
                'batch_row' => $row->id,
                'http'      => $status,
                'body'      => mb_substr($body, 0, 500),
            ]);
            $row->update([
                'status'   => AnthropicBatch::STATUS_FAILED,
                'metadata' => array_merge((array) $row->metadata, [
                    'http_status' => $status,
                    'error_body'  => mb_substr($body, 0, 1000),
                ]),
            ]);
            return $row;
        }

        $decoded = json_decode($body, true);
        $batchId = (string) ($decoded['id'] ?? '');
        if ($batchId === '') {
            Log::warning('AnthropicBatch::submit no id in response', ['body' => mb_substr($body, 0, 500)]);
            $row->update(['status' => AnthropicBatch::STATUS_FAILED]);
            return $row;
        }

        $row->update([
            'batch_id'     => $batchId,
            'status'       => AnthropicBatch::STATUS_CREATED,
            'submitted_at' => now(),
            'metadata'     => array_merge((array) $row->metadata, [
                'anthropic_response' => $decoded,
            ]),
        ]);

        Log::info('AnthropicBatch::submit ok', [
            'batch_row'     => $row->id,
            'batch_id'      => $batchId,
            'kind'          => $kind,
            'request_count' => count($requests),
        ]);

        return $row;
    }

    /**
     * Faz GET ao status do batch e actualiza a row.
     */
    public function poll(AnthropicBatch $batch): AnthropicBatch
    {
        if (!$this->isAvailable() || !$batch->batch_id) return $batch;

        try {
            $res = $this->http->get('v1/messages/batches/' . $batch->batch_id, [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'http_errors' => false,
            ]);
            $body = (string) $res->getBody();
        } catch (\Throwable $e) {
            Log::warning('AnthropicBatch::poll transport failed', ['id' => $batch->batch_id, 'error' => $e->getMessage()]);
            $batch->update(['polled_at' => now()]);
            return $batch;
        }

        $d = json_decode($body, true);
        if (!is_array($d)) {
            $batch->update(['polled_at' => now()]);
            return $batch;
        }

        $newStatus = match ((string) ($d['processing_status'] ?? '')) {
            'in_progress' => AnthropicBatch::STATUS_IN_PROGRESS,
            'canceling'   => AnthropicBatch::STATUS_IN_PROGRESS,
            'ended'       => AnthropicBatch::STATUS_ENDED,
            default       => $batch->status,
        };

        $counts = (array) ($d['request_counts'] ?? []);

        $batch->update([
            'status'           => $newStatus,
            'polled_at'        => now(),
            'ended_at'         => $newStatus === AnthropicBatch::STATUS_ENDED ? ($batch->ended_at ?? now()) : null,
            'results_succeeded'=> $counts['succeeded'] ?? null,
            'results_errored'  => $counts['errored']   ?? null,
            'results_canceled' => $counts['canceled']  ?? null,
            'results_expired'  => $counts['expired']   ?? null,
        ]);

        return $batch->refresh();
    }

    /**
     * Quando o batch está ended, descarrega o JSONL de results e
     * devolve um array indexado por custom_id:
     *   [
     *     'custom_id_X' => ['ok'=>true,  'text'=>..., 'usage'=>['input_tokens'=>...]],
     *     'custom_id_Y' => ['ok'=>false, 'error'=>'description'],
     *   ]
     *
     * Marca results_collected=true mas não apaga nada da Anthropic
     * (results ficam disponíveis por 29 dias).
     */
    public function collectResults(AnthropicBatch $batch): array
    {
        if ($batch->status !== AnthropicBatch::STATUS_ENDED) return [];
        if (!$this->isAvailable() || !$batch->batch_id) return [];

        // O endpoint de results devolve um JSONL streamable
        try {
            $res = $this->http->get('v1/messages/batches/' . $batch->batch_id . '/results', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'http_errors' => false,
                'stream'      => false,
            ]);
            $body = (string) $res->getBody();
        } catch (\Throwable $e) {
            Log::warning('AnthropicBatch::collectResults transport failed', ['id' => $batch->batch_id, 'error' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach (preg_split("/\r?\n/", $body) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $d = json_decode($line, true);
            if (!is_array($d)) continue;

            $cid = (string) ($d['custom_id'] ?? '');
            $r   = $d['result'] ?? [];
            $type = (string) ($r['type'] ?? '');

            if ($type === 'succeeded') {
                $msg = (array) ($r['message'] ?? []);
                $text = '';
                foreach ((array) ($msg['content'] ?? []) as $c) {
                    if (($c['type'] ?? '') === 'text') $text .= (string) ($c['text'] ?? '');
                }
                $out[$cid] = [
                    'ok'    => true,
                    'text'  => $text,
                    'usage' => (array) ($msg['usage'] ?? []),
                ];
            } else {
                $out[$cid] = [
                    'ok'    => false,
                    'error' => (string) ($r['error']['message'] ?? $type),
                ];
            }
        }

        $batch->update(['results_collected' => true]);

        Log::info('AnthropicBatch::collectResults ok', [
            'batch_row' => $batch->id,
            'batch_id'  => $batch->batch_id,
            'returned'  => count($out),
        ]);

        return $out;
    }
}
