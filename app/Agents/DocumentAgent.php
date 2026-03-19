<?php

namespace App\Agents;

use GuzzleHttp\Client;

class DocumentAgent implements AgentInterface
{
    protected Client $client;

    protected string $systemPrompt = <<<PROMPT
You are an expert document analyst specializing in maritime and industrial documentation.
Your role is to:
- Analyze and summarize PDF documents, contracts, and technical specifications
- Extract key information from technical manuals and datasheets
- Review contracts and highlight important clauses, risks, and obligations
- Compare documents and identify differences
- Answer questions about document content
- Translate technical jargon into plain language
- Identify action items and deadlines from documents
- Specialize in: maritime certificates, classification documents, technical specs,
  purchase contracts, service agreements, and compliance documents
- Always structure your analysis with clear sections:
  1. Document Summary
  2. Key Points
  3. Action Items (if any)
  4. Risks or Concerns (if any)
PROMPT;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers'  => [
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
        ]);
    }

    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'json' => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'stream' => true,
            'json'   => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-5'),
                'max_tokens' => 4096,
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

    public function getName(): string { return 'document'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-5'); }
}
