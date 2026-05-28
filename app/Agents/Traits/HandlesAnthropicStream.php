<?php

namespace App\Agents\Traits;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * Lê SSE stream do Anthropic com graceful handling.
 *
 * BUG histórico ("Error in input stream"):
 *   Guzzle/PSR-7 $body->read() lança quando o upstream fecha connection
 *   mid-buffer (timeout nginx, fim brusco Anthropic, etc.). Sem try/catch
 *   isto chega ao SSE controller como erro → frontend mostra
 *   "❌ Erro: Error in input stream" apesar do user ter visto resposta
 *   parcial completa.
 *
 * Solução em 2 camadas:
 *   1. message_stop event handler — sai do loop ANTES de qualquer read
 *      final que possa falhar (Anthropic SEMPRE manda este event no fim).
 *   2. try/catch em read() — se falhar mas $full já tem conteúdo, é
 *      graceful end (não propaga). Se $full vazio, é falha real → throw.
 *
 * Usado por: QuantumAgent (origem), MilDef + restantes 28 (rollout 2026-05-26).
 */
trait HandlesAnthropicStream
{
    /**
     * High-level wrapper: build request → POST → read stream → retries → emergency.
     *
     * Caso de uso típico (Engineer/MilDef/Marco/etc.):
     *
     *     $full = $this->streamAnthropicWithRetries(
     *         config: ['model' => ..., 'max_tokens' => ..., 'system' => $sys, 'messages' => $msgs, 'stream' => true],
     *         headers: $this->headersForMessage($message),
     *         onChunk: $onChunk,
     *         heartbeat: $heartbeat,
     *         heartbeatLabel: 'agent a pensar',
     *         retries: [0, 1, 3, 7],
     *     );
     *
     * Se TODAS as retries falharem com $full === '' → emergency message via $onChunk.
     * Frontend nunca vê stream completamente vazio → elimina "❌ Error in input stream".
     *
     * @param array         $config           Payload JSON Anthropic (model, max_tokens, etc.)
     * @param array         $headers          Headers para POST (incluindo x-api-key)
     * @param callable      $onChunk          function(string $text): void — chunk callback
     * @param ?callable     $heartbeat        function(string $label): void
     * @param string        $heartbeatLabel   Texto do heartbeat
     * @param array<int>    $retries          Delays em segundos antes de cada tentativa, ex: [0, 1, 3, 7]
     * @param ?string       $emergencyMessage Mensagem final se todas falham (null = throw)
     * @param ?string       $agentLabel       Label para logs
     * @param int           $heartbeatEvery   Segundos entre heartbeats
     *
     * @return string Texto completo agregado da resposta
     */
    protected function streamAnthropicWithRetries(
        array $config,
        array $headers,
        callable $onChunk,
        ?callable $heartbeat = null,
        string $heartbeatLabel = 'processando',
        array $retries = [0, 2, 5],
        ?string $emergencyMessage = null,
        ?string $agentLabel = null,
        int $heartbeatEvery = 5,
    ): string {
        $label = $agentLabel ?? class_basename(static::class);
        $lastErr = null;

        // Garante que tem client (assumimos que o agent tem $this->client Guzzle)
        if (!property_exists($this, 'client') || $this->client === null) {
            throw new \RuntimeException("{$label}: \$this->client (Guzzle) não está disponível");
        }

        foreach ($retries as $attempt => $delaySeconds) {
            if ($delaySeconds > 0) {
                if ($heartbeat) $heartbeat("⚠️ retry {$attempt} (aguarda {$delaySeconds}s)");
                sleep($delaySeconds);
            }

            try {
                $response = $this->client->post('/v1/messages', [
                    'headers' => $headers,
                    'stream'  => true,
                    'json'    => $config,
                ]);

                $full = $this->readAnthropicStream(
                    body:           $response->getBody(),
                    onDelta:        $onChunk,
                    heartbeat:      $heartbeat,
                    heartbeatEvery: $heartbeatEvery,
                    heartbeatLabel: $attempt === 0 ? $heartbeatLabel : "{$heartbeatLabel} (retry {$attempt})",
                    agentLabel:     $label,
                );

                if ($attempt > 0) {
                    Log::info("{$label}: succeeded on retry {$attempt}", [
                        'previous_errors' => $lastErr?->getMessage(),
                    ]);
                }

                return $full;
            } catch (\Throwable $err) {
                $lastErr = $err;
                Log::warning("{$label}: attempt {$attempt} failed", [
                    'error'      => $err->getMessage(),
                    'exception'  => get_class($err),
                    'next_delay' => $retries[$attempt + 1] ?? 'none',
                ]);
            }
        }

        // Todas falharam.
        Log::error("{$label}: ALL " . count($retries) . " attempts failed", [
            'last_error' => $lastErr?->getMessage(),
        ]);

        if ($emergencyMessage !== null) {
            $onChunk($emergencyMessage);
            return $emergencyMessage;
        }

        // Sem mensagem de emergência → propaga
        throw $lastErr ?? new \RuntimeException("{$label}: all retries failed (no exception captured)");
    }

    /**
     * Lê o SSE stream e devolve o texto completo concatenado.
     * Para cada text_delta chama $onDelta(string $chunk).
     *
     * Low-level: usar diretamente quando precisas de controlo customizado
     * (custom retry logic, tool-use loop, etc.). Caso contrário usa o wrapper
     * streamAnthropicWithRetries() acima.
     *
     * @param StreamInterface $body            O response body (stream)
     * @param callable        $onDelta         function(string $chunk): void
     * @param ?callable       $heartbeat       function(string $label): void (opcional)
     * @param int             $heartbeatEvery  segundos entre heartbeats (default 5)
     * @param string          $heartbeatLabel  texto do heartbeat
     * @param ?string         $agentLabel      label para logs (default = classe)
     *
     * @return string Texto completo agregado
     */
    protected function readAnthropicStream(
        StreamInterface $body,
        callable $onDelta,
        ?callable $heartbeat = null,
        int $heartbeatEvery = 5,
        string $heartbeatLabel = 'processando',
        ?string $agentLabel = null,
    ): string {
        $full     = '';
        $buf      = '';
        $lastBeat = time();
        $label    = $agentLabel ?? class_basename(static::class);

        while (!$body->eof()) {
            // 1. Read defensivo — graceful end se já temos conteúdo
            try {
                $buf .= $body->read(1024);
            } catch (\Throwable $readErr) {
                if ($full === '') {
                    // Falha real (nem 1 byte recebido) → propaga
                    throw $readErr;
                }
                Log::info("{$label}: stream read error após resposta completa — graceful end", [
                    'msg' => $readErr->getMessage(),
                    'len' => strlen($full),
                ]);
                break;
            }

            // 2. Parse SSE lines
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;

                // 3. message_stop — exit ANTES do read final que pode falhar
                if (($evt['type'] ?? '') === 'message_stop') break 2;

                // 4. text_delta — chunk de texto
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $text = $evt['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        $onDelta($text);
                    }
                }
            }

            // 5. Heartbeat (keep-alive para mobile / proxies)
            if ($heartbeat && (time() - $lastBeat) >= $heartbeatEvery) {
                $heartbeat($heartbeatLabel);
                $lastBeat = time();
            }
        }

        return $full;
    }
}
