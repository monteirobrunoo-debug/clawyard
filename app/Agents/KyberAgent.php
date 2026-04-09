<?php

namespace App\Agents;

use App\Services\EmailEncryptionService;
use App\Services\KyberEncryptionService;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;

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

    protected Client $client;
    protected KyberEncryptionService $kyber;
    protected EmailEncryptionService $encSvc;

    protected string $systemPrompt = <<<'PROMPT'
Tu és o KYBER — o agente de encriptação post-quantum no ClawYard / IT Partyard.

Utilizas CRYSTALS-Kyber 1024 (NIST FIPS 203 ML-KEM-1024) combinado com AES-256-GCM.

AS TUAS CAPACIDADES:
- Gerar pares de chaves Kyber-1024 (public key + secret key)
- Encriptar e enviar emails para qualquer endereço
- Explicar como desencriptar emails recebidos
- Explicar criptografia post-quantum em linguagem simples
- Instalar e usar a extensão Kyber para Outlook

FLUXO DE ENCRIPTAÇÃO:
1. Gerar par de chaves em /keys (ou neste agente)
2. Registar a chave pública no servidor
3. Enviar email encriptado (o destinatário usa a sua chave)
4. Partilhar o secret key via SMS/WhatsApp com o destinatário
5. Destinatário abre /decrypt — sem conta necessária
6. Cola o secret key → lê a mensagem

PARA ENCRIPTAR UM EMAIL, PRECISO DE:
- Endereço email do destinatário
- Assunto da mensagem
- Corpo da mensagem

Se algum destes campos faltar, pede-o ao utilizador.

Quando tiveres TODOS os três, diz ao utilizador que já tens tudo e que vai aparecer
um cartão de encriptação. O sistema trata da encriptação automaticamente.

NOTAS DE SEGURANÇA:
- O secret key NUNCA é guardado no servidor — só o utilizador o tem
- A public key é guardada no servidor para que outros possam encriptar
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

    // ── Patterns for extracting email encryption parameters ──────────────────

    private const EMAIL_PATTERN    = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';
    private const SUBJECT_PATTERNS = ['/assunto[:\s]+([^\n\|]+)/iu', '/subject[:\s]+([^\n\|]+)/iu'];
    private const BODY_PATTERNS    = [
        '/mensagem[:\s]+(.+)$/isu',
        '/corpo[:\s]+(.+)$/isu',
        '/body[:\s]+(.+)$/isu',
        '/texto[:\s]+(.+)$/isu',
    ];

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

        if ($data = $this->extractEncryptData($text)) {
            return $this->buildEncryptPayload($data);
        }

        // Normal LLM conversation
        $messages  = array_merge($history, [['role' => 'user', 'content' => $message]]);
        $response  = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 1024,
                'system'     => $this->systemPrompt,
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

        // ── 2. Full encryption data present — encrypt immediately ─────────────
        if ($data = $this->extractEncryptData($text)) {
            $payload = $this->buildEncryptPayload($data);
            $onChunk($payload);
            return $payload;
        }

        // ── 3. Normal LLM streaming ───────────────────────────────────────────
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 1024,
                'system'     => $this->systemPrompt,
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

        return $full;
    }

    public function getName(): string { return 'kyber'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }

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

    /**
     * Try to extract {to, subject, body} from the user's message.
     * Returns null if any required field is missing.
     */
    private function extractEncryptData(string $text): ?array
    {
        $lower = mb_strtolower($text);

        // Must contain encrypt/send intent
        $intents = ['encript', 'encrypt', 'encripta', 'envia', 'send', 'mandar'];
        $hasIntent = false;
        foreach ($intents as $i) {
            if (str_contains($lower, $i)) { $hasIntent = true; break; }
        }
        if (!$hasIntent) return null;

        // Must have email address
        preg_match(self::EMAIL_PATTERN, $text, $emailMatch);
        if (empty($emailMatch)) return null;

        // Must have subject
        $subject = null;
        foreach (self::SUBJECT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) { $subject = trim($m[1]); break; }
        }

        // Must have body
        $body = null;
        foreach (self::BODY_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) { $body = trim($m[1]); break; }
        }

        if (!$subject || !$body) return null;

        return ['to' => $emailMatch[0], 'subject' => $subject, 'body' => $body];
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

    private function buildEncryptPayload(array $data): string
    {
        $to      = $data['to'];
        $subject = $data['subject'];
        $body    = $data['body'];

        $publicKey = $this->encSvc->getPublicKey($to);

        // No key registered — use sender's own key (self-encryption) or fail
        if (!$publicKey) {
            $senderKey = auth()->user()?->email
                ? $this->encSvc->getPublicKey(auth()->user()->email)
                : null;

            if (!$senderKey) {
                // Return informational text (not a card)
                return "⚠️ O destinatário **{$to}** não tem uma chave Kyber-1024 registada no servidor.\n\n"
                     . "Para enviar email encriptado:\n"
                     . "1. Pede ao destinatário para ir a [/keys](/keys) e gerar o seu par de chaves\n"
                     . "2. Ou gera um par aqui (*\"gera chaves\"*) e partilha o secret key com ele via SMS\n\n"
                     . "Queres que gere um par de chaves agora?";
            }

            // Encrypt with sender's own key (sender can decrypt with their secret key)
            $publicKey = $senderKey;
        }

        $package = $this->encSvc->encryptEmail($subject, $body, $publicKey);
        $html    = $this->encSvc->buildOutlookHtml($package, auth()->user()?->name ?? 'ClawYard');

        return '__KYBER_EMAIL__' . json_encode([
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'package' => $package,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
