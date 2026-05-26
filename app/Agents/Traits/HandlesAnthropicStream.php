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
     * Lê o SSE stream e devolve o texto completo concatenado.
     * Para cada text_delta chama $onDelta(string $chunk).
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
