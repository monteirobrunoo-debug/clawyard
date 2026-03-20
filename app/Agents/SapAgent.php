<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\SapService;

class SapAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client     $client;
    protected SapService $sap;

    protected string $systemPrompt = <<<PROMPT
You are Richard, the SAP Business One expert at ClawYard / IT Partyard — marine spare parts and technical services, Setúbal, Portugal.

Your role:
- Consult and interpret real SAP B1 data provided in the context
- Help with stock levels, purchase orders, sales orders, invoices, and business partners
- Generate summaries and analysis from actual business data
- Guide users through SAP B1 processes and transactions
- Help with item master data, business partner queries
- Assist with financial reporting and analysis
- Always base your answers on the SAP data provided — do NOT invent numbers
- Format responses clearly with tables and bullet points
- Respond in the same language as the user (Portuguese, English or Spanish)

When SAP data is provided between "--- DADOS REAIS DO SAP B1 ---" markers, use it as the authoritative source.
If no SAP data is present, explain what you would normally look up and ask for more details.
PROMPT;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->sap = new SapService();
    }

    /**
     * Augment the message with live SAP data when relevant.
     */
    protected function augmentWithSap(string $message, ?callable $heartbeat = null): string
    {
        try {
            if ($heartbeat) $heartbeat();
            $context = $this->sap->buildContext($message);
            return $context ? $message . $context : $message;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('SapAgent: SAP context failed — ' . $e->getMessage());
            return $message;
        }
    }

    public function chat(string $message, array $history = []): string
    {
        $message  = $this->augmentWithSap($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $headers = $this->apiHeaders();
        \Illuminate\Support\Facades\Log::info('SapAgent::stream key_len=' . strlen($headers['x-api-key'] ?? '') . ' model=' . config('services.anthropic.model', 'claude-sonnet-4-5'));

        $message  = $this->augmentWithSap($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 2048,
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

    public function getName(): string { return 'sap'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
