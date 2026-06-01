<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\LogisticsSkillTrait;
use App\Agents\Traits\WebSearchTrait;

class GrokAgent implements AgentInterface
{
    use SharedContextTrait;
    use LogisticsSkillTrait;
    use WebSearchTrait;

    protected string $searchPolicy = 'conditional';
    protected Client $client;

    protected string $contextKey  = 'grok_intel';
    protected array  $contextTags = ['grok','xai','analysis','reasoning','direct'];

    protected string $systemPromptBase = <<<'PROMPT'
You are Alex Grok — ClawYard's analytical assistant powered by xAI Grok.
You are direct, precise and concise. Answer in the user's language (PT default, EN when asked).
You excel at reasoning, analysis and cutting through complexity.
Use the shared agent intel block when provided to stay consistent with the
rest of the platform. Never expose internal credentials, hostnames or
CardCodes verbatim — summarise instead.
PROMPT;

    public function __construct()
    {
        $baseUri = (string) config('services.grok.base_url', 'https://api.x.ai/v1');
        if (!str_starts_with(strtolower($baseUri), 'https://')) {
            $baseUri = preg_replace('#^http://#i', 'https://', $baseUri) ?: 'https://api.x.ai/v1';
        }

        $this->client = new Client([
            'base_uri'        => $baseUri,
            'headers'         => [
                'Authorization' => 'Bearer ' . config('services.grok.api_key'),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ClawYard/1.0 (+https://clawyard.partyard.eu)',
            ],
            'verify'          => env('GROK_CA_BUNDLE', true),
            'curl'            => [
                CURLOPT_SSLVERSION      => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
            ],
            'connect_timeout' => 10,
            'timeout'         => 300,
            'http_errors'     => true,
        ]);

        $this->systemPromptBase .= $this->logisticsSkillPromptBlock();
    }

    public function chat(string|array $message, array $history = []): string
    {
        $message      = $this->smartAugment($message);
        $systemPrompt = $this->enrichSystemPrompt($this->systemPromptBase, $this->messageText($message));

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $response = $this->client->post('/v1/chat/completions', [
            'json' => [
                'model'       => config('services.grok.model'),
                'messages'    => $messages,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text !== '') $this->publishSharedContext($text);
        return $text;
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $message      = $this->smartAugment($message, $heartbeat);
        $systemPrompt = $this->enrichSystemPrompt($this->systemPromptBase, $this->messageText($message));

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        $response = $this->client->post('/v1/chat/completions', [
            'stream' => true,
            'json'   => [
                'model'       => config('services.grok.model'),
                'messages'    => $messages,
                'max_tokens'  => 8192,
                'temperature' => 0.7,
                'stream'      => true,
            ],
        ]);

        $body = $response->getBody();
        $full = '';
        $buf  = '';

        while (!$body->eof()) {
            try {
                $buf .= $body->read(1024);
            } catch (\Throwable $readErr) {
                if ($full === '') throw $readErr;
                \Log::info('grok stream read graceful end after partial response', ['msg' => $readErr->getMessage(), 'len' => strlen($full)]);
                break;
            }
            while (($pos = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $pos);
                $buf  = substr($buf, $pos + 1);
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') break 2;
                $evt = json_decode($json, true);
                if (!is_array($evt)) continue;
                $text = $evt['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') {
                    $full .= $text;
                    $onChunk($text);
                }
            }
        }

        if ($full !== '') $this->publishSharedContext($full);

        return $full;
    }

    public function getName(): string { return 'grok'; }
    public function getModel(): string { return config('services.grok.model'); }

    protected function messageText(string|array $message): string
    {
        if (is_string($message)) return $message;
        foreach ($message as $block) {
            if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                return (string) $block['text'];
            }
        }
        return '';
    }
}
