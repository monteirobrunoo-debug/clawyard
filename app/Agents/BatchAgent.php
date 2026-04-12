<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

/**
 * BatchAgent — "Max Batch"
 *
 * Uses Anthropic Message Batches API to process multiple items
 * asynchronously at 50% cost. Ideal for: bulk email generation,
 * processing lists of suppliers/parts, multi-document analysis.
 */
class BatchAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'never';

    protected Client $client;

    public function __construct()
    {
        $persona = 'You are **Max Batch** — the bulk processing specialist for HP-Group / PartYard.';

        $specialty = <<<'SPECIALTY'
You excel at processing large volumes of items efficiently. Your capabilities:

📦 BULK PROCESSING:
- Process lists of suppliers, parts, or documents in one go
- Generate multiple emails or documents from a template
- Analyse multiple contracts or proposals simultaneously
- Cross-reference large datasets

📧 BATCH EMAIL GENERATION:
- Generate personalised emails for multiple recipients
- Adapt tone and content per recipient type
- Apply PartYard brand voice consistently

📊 BULK ANALYSIS:
- Analyse lists of part numbers against SAP inventory
- Cross-reference supplier lists with pricing data
- Process multiple quotes or proposals

🔄 HOW TO USE:
- Send me a list with items separated by lines or numbers
- Tell me what you want done with each item
- I'll process all items and return structured results

FORMAT:
- Process each item clearly labeled (Item 1, Item 2, etc.)
- Provide a summary at the end
- Flag any items that need human review
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 300,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
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
        if ($heartbeat) $heartbeat('a processar em lote 📦');

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

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
                    $chunk = $evt['delta']['text'] ?? '';
                    if ($chunk !== '') {
                        $full .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
            if ($heartbeat && (time() - $lastBeat) >= 4) {
                $heartbeat('a processar itens…');
                $lastBeat = time();
            }
        }

        return $full;
    }

    public function getName(): string  { return 'batch'; }
    public function getModel(): string { return config('services.anthropic.model', 'claude-sonnet-4-6'); }
}
