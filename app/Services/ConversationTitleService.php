<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Auto-generates a 3-6 word title for a conversation from the first
 * user message + first assistant reply.
 *
 * Why a separate service?
 *   • Keeps the chatStream controller short and testable.
 *   • Allows the title to be regenerated in batch later (e.g. a
 *     conversations:retitle command for existing rows with NULL title).
 *
 * Cost: ~$0.0001 per conversation (Haiku 4.5, ~200 input + 20 output tokens).
 * Latency: ~600ms. Called AFTER the SSE stream completes, so users never
 * see it; if it fails or times out, the conversation just stays untitled.
 */
class ConversationTitleService
{
    private const MAX_TITLE_CHARS = 80;
    private const SYSTEM_PROMPT   = "És um gerador de títulos curtos para conversas de chatbot empresarial. "
                                  . "Dado uma pergunta do utilizador e a primeira resposta do assistente, "
                                  . "produzes APENAS 3 a 6 palavras descritivas em português, "
                                  . "sem aspas, sem pontuação final, sem emojis. "
                                  . "Captura o tópico principal — concursos, leads, peças, fiscalidade, etc. "
                                  . "Exemplos: 'Concurso aeronaves marinha 2026' · 'Tributação autónoma viaturas' · "
                                  . "'Lead SAP Hamburgo' · 'Pesquisa fornecedores radar'.";

    public function generate(string $userMessage, string $assistantReply): ?string
    {
        $apiKey = config('services.anthropic.api_key') ?: env('ANTHROPIC_API_KEY');
        if (!$apiKey) return null;

        // Trim inputs aggressively — title only needs gist, not full context.
        $user = mb_substr(trim(strip_tags($userMessage)), 0, 500);
        $reply = mb_substr(trim(strip_tags($assistantReply)), 0, 500);
        if ($user === '') return null;

        try {
            $client = new Client(['base_uri' => 'https://api.anthropic.com', 'timeout' => 8.0]);
            $resp = $client->post('/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => 'claude-haiku-4-5',
                    'max_tokens' => 40,
                    'system'     => self::SYSTEM_PROMPT,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => "User: {$user}\n\nAssistente: {$reply}\n\nTítulo:",
                    ]],
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $raw  = trim((string) ($data['content'][0]['text'] ?? ''));

            return $this->sanitize($raw);
        } catch (\Throwable $e) {
            Log::debug('ConversationTitleService: ' . $e->getMessage());
            return null;
        }
    }

    private function sanitize(string $raw): ?string
    {
        // Strip quotes/punctuation Haiku occasionally still adds despite
        // explicit instruction.
        $title = trim($raw, " \t\n\r\0\x0B\"'.,;:!?—-");
        $title = preg_replace('/\s+/', ' ', $title) ?: '';
        if (mb_strlen($title) < 3 || mb_strlen($title) > self::MAX_TITLE_CHARS) {
            return null;
        }
        // Never let prompt-injection content (e.g. "ignore previous") leak
        // into a stored title. Strip control chars + obvious injection prefixes.
        if (preg_match('/^(ignore|system|prompt|você|assistant)\b/i', $title)) {
            return null;
        }
        return $title;
    }
}
