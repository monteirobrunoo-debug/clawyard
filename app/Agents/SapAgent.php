<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\SapService;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

class SapAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;

    protected Client     $client;
    protected SapService $sap;

    protected string $systemPrompt = <<<PROMPT
You are Richard, the SAP Business One expert at ClawYard / PartYard — marine spare parts and technical services, Setúbal, Portugal.

[PROFILE_PLACEHOLDER]

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

    // Keywords that justify a live web search alongside SAP data
    protected array $webSearchKeywords = [
        'mercado', 'market', 'preço', 'price', 'concorrente', 'competitor',
        'tendência', 'trend', 'notícia', 'news', 'câmbio', 'exchange rate',
    ];

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);

        $this->sap = new SapService();
    }

    protected function needsWebSearch(string|array $message): bool
    {
        $message = $this->messageText($message);
        $lower = strtolower($message);
        foreach ($this->webSearchKeywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    protected function augmentWithSap(string|array $message, ?callable $heartbeat = null): string|array
    {
        try {
            if ($heartbeat) $heartbeat('a consultar SAP');
            $context = $this->sap->buildContext($this->messageText($message));
            return $context ? $this->appendToMessage($message, $context) : $message;
        } catch (\Throwable $e) {
            Log::warning('SapAgent: SAP context failed — ' . $e->getMessage());
            return $message;
        }
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message  = $this->augmentWithSap($message);
        $message = $this->augmentWithWebSearch($message);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message = $this->augmentWithSap($message, $heartbeat);
        $message = $this->augmentWithWebSearch($message, $heartbeat);
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->apiHeaders(),
            'stream'  => true,
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
                'stream'     => true,
            ],
        ]);

        $body     = $response->getBody();
        $full     = '';
        $buf      = '';
        $lastBeat = time();

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
            if ($heartbeat && (time() - $lastBeat) >= 10) {
                $heartbeat('a processar');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string { return 'sap'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
