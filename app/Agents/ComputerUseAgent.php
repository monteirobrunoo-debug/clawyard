<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Agents\Traits\WebSearchTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use Illuminate\Support\Facades\Log;

/**
 * ComputerUseAgent — "RoboDesk"
 *
 * Web research and automation specialist for HP-Group / PartYard.
 * Uses Tavily web search to navigate the web, find supplier pricing,
 * monitor procurement portals, and extract structured data from online sources.
 */
class ComputerUseAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use WebSearchTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'always';

    protected Client $client;

    protected array $webSearchKeywords = [
        // Always search (RoboDesk is a web agent by design)
        '', // empty string matches everything
    ];

    public function __construct()
    {
        $persona = 'You are **RoboDesk** — the browser and desktop automation specialist for HP-Group / PartYard. You control the computer to get things done.';

        $specialty = <<<'SPECIALTY'
You are not just a researcher — you are an automation agent that controls browsers, fills forms, navigates portals and interacts with desktop applications on behalf of the user.

Think of yourself as a skilled human sitting at a computer who can:
- Open any website or web app
- Log in to portals (when credentials are provided by the user)
- Navigate menus, click buttons, fill forms
- Take screenshots and describe what's on screen
- Extract data from pages and export it
- Automate repetitive multi-step tasks
- Open and interact with local files (Excel, Word, PDFs)

🖥️ TASKS YOU HANDLE:
- SAP B1 navigation: open screens, export reports, capture data from tables
- Portal automation: BASE.gov, ACINGOV, DGAM, TED Europa, NATO eSourcing
- Email management: open Gmail/Outlook, find emails, read threads, draft replies
- Form filling: supplier portals, procurement registrations, tender submissions
- Price research: systematically open supplier sites and compare prices
- File operations: open Excel, update cells, save files
- Screenshot audits: capture what's on screen and describe issues found
- Repetitive data entry: take a list and enter items one by one into a system

📋 HOW YOU WORK:
1. **Understand** the task — what needs to be done, on which system
2. **Plan** the steps: which site/app, what to click, what to fill in
3. **Execute** step by step, taking screenshots to confirm progress
4. **Report** back with results, extracted data or confirmation of completion

IMPORTANT RULES:
- NEVER enter passwords unless the user explicitly provides them in this conversation
- NEVER make purchases, financial transactions or confirm irreversible actions without user confirmation
- If you get stuck or hit a CAPTCHA, tell the user and ask how to proceed
- Always describe what you see on screen so the user knows what's happening
- When extracting data, return it in a clean structured format (table, list, JSON)
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function chat(string|array $message, array $history = []): string
    {
        // Always augment with web search — RoboDesk is a web agent
        $augmented = $this->augmentWithWebSearch($message);

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => $this->headersForMessage($augmented),
                'json'    => [
                    'model'      => config('services.anthropic.model', 'claude-sonnet-4-6'),
                    'max_tokens' => 8192,
                    'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                    'messages'   => $messages,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['content'][0]['text'] ?? '';
        } catch (\Throwable $e) {
            Log::error('ComputerUseAgent chat error: ' . $e->getMessage());
            return '❌ Erro ao processar pedido. Tente novamente.';
        }
    }

    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        if ($heartbeat) $heartbeat('a pesquisar na web 🌐');

        // Always do web search — RoboDesk's core capability
        $augmented = $this->augmentWithWebSearch($message, $heartbeat);

        if ($heartbeat) $heartbeat('a analisar resultados 🖥️');

        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $augmented],
        ]);

        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => $this->headersForMessage($augmented),
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
                    $heartbeat('a compilar dados…');
                    $lastBeat = time();
                }
            }

            return $full;

        } catch (\Throwable $e) {
            Log::error('ComputerUseAgent stream error: ' . $e->getMessage());
            $err = '❌ Erro de ligação. Por favor tente novamente.';
            $onChunk($err);
            return $err;
        }
    }

    public function getName(): string  { return 'computer'; }
    public function getModel(): string { return 'claude-sonnet-4-6'; }
}
