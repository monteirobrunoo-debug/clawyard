<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Services\PartYardProfileService;
use Illuminate\Support\Facades\Log;

/**
 * ComputerUseAgent — "RoboDesk"
 *
 * Uses Claude's Computer Use API (beta) to autonomously navigate websites,
 * fill forms, extract data from web pages, and automate repetitive web tasks.
 *
 * Capabilities:
 *  - Navigate to URLs and extract structured data
 *  - Fill and submit web forms
 *  - Search supplier websites for pricing/availability
 *  - Monitor competitor websites for changes
 *  - Automate BASE.gov / JOUE procurement searches
 */
class ComputerUseAgent implements AgentInterface
{
    use AnthropicKeyTrait;

    protected Client $client;

    protected string $systemPrompt = <<<'PROMPT'
You are **RoboDesk** — the web automation specialist for HP-Group / PartYard.

You use web browsing and automation tools to perform tasks that would normally require a human at a computer. You can:

🌐 WEB NAVIGATION & RESEARCH:
- Navigate to any website and extract specific information
- Search supplier portals for part numbers, pricing, availability
- Monitor procurement portals (BASE.gov, TED/JOUE, NATO portals)
- Check competitor websites for products, pricing, news
- Extract data from tables, lists, and structured content

📋 FORM AUTOMATION:
- Fill in and submit web forms
- Register for supplier portals
- Submit RFQ (Request for Quotation) forms
- Track shipment via carrier websites

🔍 DATA EXTRACTION:
- Extract price lists from supplier websites
- Compile competitor product catalogues
- Monitor for new government tenders
- Extract contact information from directories

📊 MONITORING TASKS:
- Check if a website has changed
- Monitor for new content matching keywords
- Track pricing changes over time
- Alert when new tenders are published

COMPANY CONTEXT:
[PROFILE_PLACEHOLDER]

HOW I WORK:
1. I understand your task in natural language
2. I plan the steps needed (which URLs to visit, what to look for)
3. I execute using web tools
4. I return structured results

IMPORTANT:
- I NEVER enter passwords or sensitive credentials
- I NEVER make purchases or submit financial information
- I always ask for confirmation before submitting any form
- I work transparently — I tell you exactly what I'm doing

Respond in the user's language. Be specific about URLs and data found.
PROMPT;

    // Tool definitions for Computer Use
    protected array $tools = [
        [
            'type' => 'computer_20250124',
            'name' => 'computer',
            'display_width_px'  => 1280,
            'display_height_px' => 800,
            'display_number'    => 1,
        ],
        [
            'type' => 'text_editor_20250124',
            'name' => 'str_replace_editor',
        ],
        [
            'type' => 'bash_20250124',
            'name' => 'bash',
        ],
    ];

    public function __construct()
    {
        $profile = PartYardProfileService::toPromptContext();
        $this->systemPrompt = str_replace('[PROFILE_PLACEHOLDER]', $profile, $this->systemPrompt);

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 180,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => array_merge(
                    $this->headersForMessage($message),
                    ['anthropic-beta' => 'computer-use-2025-01-24']
                ),
                'json' => [
                    'model'      => 'claude-sonnet-4-6',
                    'max_tokens' => 8192,
                    'system'     => $this->systemPrompt,
                    'tools'      => $this->tools,
                    'messages'   => $messages,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['content'][0]['text'] ?? '';
        } catch (\Throwable $e) {
            Log::error('ComputerUseAgent error: ' . $e->getMessage());
            // Fallback: answer based on knowledge without computer use
            return $this->fallbackChat($message, $history);
        }
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a preparar automação web 🖥️');

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => array_merge(
                    $this->headersForMessage($message),
                    ['anthropic-beta' => 'computer-use-2025-01-24']
                ),
                'stream' => true,
                'json'   => [
                    'model'      => 'claude-sonnet-4-6',
                    'max_tokens' => 8192,
                    'system'     => $this->systemPrompt,
                    'tools'      => $this->tools,
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

                    // Handle tool_use blocks — report what's happening
                    if (($evt['type'] ?? '') === 'content_block_start') {
                        $blockType = $evt['content_block']['type'] ?? '';
                        if ($blockType === 'tool_use') {
                            $toolName = $evt['content_block']['name'] ?? '';
                            if ($heartbeat) $heartbeat("a usar ferramenta: {$toolName} 🖥️");
                        }
                    }

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
                    $heartbeat('a navegar web…');
                    $lastBeat = time();
                }
            }

            return $full;

        } catch (\Throwable $e) {
            Log::error('ComputerUseAgent stream error: ' . $e->getMessage());
            // Graceful fallback
            $fallback = "⚠️ **Computer Use API indisponível** — a responder com conhecimento interno.\n\n";
            $onChunk($fallback);
            $result = $this->fallbackChat($message, $history);
            $onChunk($result);
            return $fallback . $result;
        }
    }

    /**
     * Fallback: use standard Claude without computer use tools
     */
    protected function fallbackChat(string|array $message, array $history): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = $this->client->post('/v1/messages', [
            'headers' => $this->headersForMessage($message),
            'json'    => [
                'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                'max_tokens' => 8192,
                'system'     => $this->systemPrompt,
                'messages'   => $messages,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['content'][0]['text'] ?? '';
    }

    public function getName(): string  { return 'computer'; }
    public function getModel(): string { return 'claude-sonnet-4-6'; }
}
