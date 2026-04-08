<?php

namespace App\Agents;

use App\Services\EmailEncryptionService;
use App\Services\KyberEncryptionService;
use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;

/**
 * Kyber Agent — post-quantum email encryption assistant.
 *
 * Handles key generation, encrypted email sending, and decryption
 * guidance via natural language. Backed by KyberEncryptionService.
 */
class KyberAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client $client;
    protected KyberEncryptionService $kyber;
    protected EmailEncryptionService $encSvc;

    protected string $systemPrompt = <<<'PROMPT'
You are KYBER — the post-quantum encryption agent at ClawYard / IT Partyard.

You handle all encryption-related tasks using CRYSTALS-Kyber 1024 (NIST FIPS 203 ML-KEM-1024),
a post-quantum secure Key Encapsulation Mechanism combined with AES-256-GCM.

YOUR CAPABILITIES:
- Generate Kyber-1024 key pairs (public key + secret key)
- Send encrypted emails to any email address
- Explain how to decrypt a received encrypted email
- Explain how post-quantum encryption works
- Guide users through the full encryption workflow

WORKFLOW YOU EXPLAIN TO USERS:
1. Generate a key pair at /keys
2. Register the public key on the server
3. Send an encrypted email to any address (uses your key)
4. Share the secret key via SMS/WhatsApp to the recipient
5. Recipient opens clawyard.partyard.eu/decrypt — no account needed
6. Recipient pastes the secret key + clicks decrypt → reads the message

WHEN A USER ASKS TO:
- "send encrypted email to X" → confirm you will send it and ask for subject/body if not provided
- "generate keys" or "create key pair" → tell them to go to /keys
- "how to decrypt" → explain: open /decrypt, paste secret key + JSON from email
- "what is Kyber" → explain post-quantum cryptography in simple terms

SECURITY NOTES:
- The secret key is NEVER stored on the server — only the user has it
- The public key is stored on the server for others to encrypt to the user
- AES-256-GCM ensures message integrity — tampering is detected automatically
- Kyber-1024 is quantum-computer resistant (NIST Category 5)

Always respond in the same language as the user (Portuguese or English).
Be concise and helpful. When sending emails, confirm what was sent.
PROMPT;

    public function __construct()
    {
        $this->client  = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
        $this->kyber   = new KyberEncryptionService();
        $this->encSvc  = new EmailEncryptionService($this->kyber);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
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
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

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
                    $text = $evt['delta']['text'] ?? '';
                    if ($text !== '') {
                        $full .= $text;
                        $onChunk($text);
                    }
                }
            }
        }

        return $full;
    }

    public function getName(): string { return 'kyber'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
