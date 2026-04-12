<?php

namespace App\Agents;

use App\Services\EmailEncryptionService;
use App\Services\KyberEncryptionService;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;

/**
 * Kyber Agent — post-quantum email encryption assistant.
 *
 * Handles key generation, encrypted email composition/sending, and decryption
 * guidance via natural language. Backed by KyberEncryptionService.
 *
 * Special outputs (detected by frontend):
 *   __KYBER_KEYS__{...}   → renders a key-pair card with copy + store buttons
 *   __KYBER_EMAIL__{...}  → renders an encrypted email card with send button
 */
class KyberAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'never';

    protected Client $client;
    protected KyberEncryptionService $kyber;
    protected EmailEncryptionService $encSvc;

    protected string $systemPrompt = <<<'PROMPT'
Tu és o KYBER — o agente de encriptação post-quantum no ClawYard / IT Partyard.

Utilizas CRYSTALS-Kyber 1024 (NIST FIPS 203 ML-KEM-1024) combinado com AES-256-GCM.

AS TUAS CAPACIDADES:
- Gerar pares de chaves Kyber-1024 (public key + secret key)
- Encriptar e enviar emails para qualquer endereço (via cartão de composição)
- Explicar como desencriptar emails recebidos
- Explicar criptografia post-quantum em linguagem simples

REGRA CRÍTICA — ENVIO DE EMAIL:
Se o utilizador mencionar email + encriptação/envio/seguro/kyber em qualquer forma,
responde APENAS com este texto exacto (sem mais nada):
__KYBER_COMPOSE__{}
O sistema substitui este marcador por um formulário de composição interactivo.
NUNCA expliques passos. NUNCA peças campos. O formulário trata de tudo.

NOTAS DE SEGURANÇA:
- O secret key NUNCA é guardado no servidor — só o utilizador o tem
- AES-256-GCM garante integridade — adulteração é detectada automaticamente
- Kyber-1024 é resistente a computadores quânticos (NIST Categoria 5)

Responde sempre no mesmo idioma do utilizador (Português ou Inglês). Sê conciso e útil.
PROMPT;

    // ── Keyword triggers for direct key generation (no LLM needed) ───────────

    private const KEYGEN_TRIGGERS = [
        'gera chave', 'gerar chave', 'criar chave', 'cria chave',
        'generate key', 'new key', 'create key', 'par de chaves',
        'gerar par', 'keypair', 'key pair', 'nova chave',
        'quero chaves', 'preciso de chaves', 'preciso chaves',
        'gera-me', 'cria-me as chaves',
    ];

    // ── Keyword triggers for email compose form (shows editable card) ─────────

    private const COMPOSE_TRIGGERS = [
        'encriptar email', 'encriptar um email', 'encripta email',
        'enviar email', 'enviar um email', 'envia email',
        'email seguro', 'email encriptado', 'email cifrado',
        'encrypt email', 'send email', 'compose email',
        'escrever email', 'escrever um email', 'novo email',
        'mandar email', 'mandar um email',
    ];

    // ── Pattern for extracting email address from message (optional pre-fill) ─

    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    public function __construct()
    {
        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
        $this->kyber  = new KyberEncryptionService();
        $this->encSvc = new EmailEncryptionService($this->kyber);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public interface
    // ─────────────────────────────────────────────────────────────────────────

    public function chat(string|array $message, array $history = []): string
    {
        $text = $this->extractText($message);

        if ($this->isKeyGenTrigger($text)) {
            return $this->buildKeyGenPayload();
        }

        if ($this->isEmailComposeTrigger($text)) {
            return $this->buildComposePayload($text);
        }

        // Normal LLM conversation
        $messages  = array_merge($history, [['role' => 'user', 'content' => $message]]);
        $response  = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
            ],
        ]);
        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $text = $this->extractText($message);

        // ── 1. Key generation — instant, no LLM ──────────────────────────────
        if ($this->isKeyGenTrigger($text)) {
            $payload = $this->buildKeyGenPayload();
            $onChunk($payload);
            return $payload;
        }

        // ── 2. Email compose intent — show editable form card ────────────────
        if ($this->isEmailComposeTrigger($text)) {
            $payload = $this->buildComposePayload($text);
            $onChunk($payload);
            return $payload;
        }

        // ── 3. Normal LLM streaming ───────────────────────────────────────────
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

        while (!$body->eof()) {
            $buf .= $body->read(1024);
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                if (($evt['type'] ?? '') === 'content_block_delta'
                    && ($evt['delta']['type'] ?? '') === 'text_delta') {
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $full .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
        }

        // If LLM output the compose marker, replace accumulated with clean payload
        if (str_contains($full, '__KYBER_COMPOSE__')) {
            $payload = $this->buildComposePayload($text);
            return $payload; // frontend DONE handler checks includes(), not just startsWith()
        }

        return $full;
    }

    public function getName(): string { return 'kyber'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }

    // ─────────────────────────────────────────────────────────────────────────
    // Intent detection
    // ─────────────────────────────────────────────────────────────────────────

    private function extractText(string|array $message): string
    {
        if (is_string($message)) return $message;
        foreach ((array) $message as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') return $part['text'] ?? '';
        }
        return '';
    }

    private function isKeyGenTrigger(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::KEYGEN_TRIGGERS as $trigger) {
            if (str_contains($lower, $trigger)) return true;
        }
        return false;
    }

    private function isEmailComposeTrigger(string $text): bool
    {
        $lower = mb_strtolower($text);

        // Exact phrase triggers
        foreach (self::COMPOSE_TRIGGERS as $trigger) {
            if (str_contains($lower, $trigger)) return true;
        }

        // Heuristic: "email" + any encryption/send keyword
        if (str_contains($lower, 'email')) {
            foreach (['encript', 'encrypt', 'cifr', 'segur', 'kyber', 'quantum', 'secret'] as $kw) {
                if (str_contains($lower, $kw)) return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Action payloads
    // ─────────────────────────────────────────────────────────────────────────

    private function buildKeyGenPayload(): string
    {
        $pair = $this->kyber->generateKeyPair();

        return '__KYBER_KEYS__' . json_encode([
            'public_key' => $pair['public_key'],
            'secret_key' => $pair['secret_key'],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a compose-form card payload. Optionally pre-fills the "to" field
     * if an email address was detected in the user's message.
     */
    private function buildComposePayload(string $text): string
    {
        $to = '';
        preg_match(self::EMAIL_PATTERN, $text, $match);
        if (!empty($match)) {
            $to = $match[0];
        }

        return '__KYBER_COMPOSE__' . json_encode([
            'to' => $to,
        ], JSON_UNESCAPED_SLASHES);
    }
}
