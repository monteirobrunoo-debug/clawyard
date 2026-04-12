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

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'always';

    protected Client $client;

    protected array $webSearchKeywords = [
        // Always search (RoboDesk is a web agent by design)
        '', // empty string matches everything
    ];

    public function __construct()
    {
        $persona = 'You are **RoboDesk** — the web automation and research specialist for HP-Group / PartYard.';

        $specialty = <<<'SPECIALTY'
Your job is to search the web and find precise, actionable information. You work like a human researcher at a computer — but faster and more systematic.

🌐 WHAT YOU DO:
- Search supplier portals for part numbers, pricing and availability (MTU, CAT, MAK, SKF, Wärtsilä, Schottel, etc.)
- Monitor procurement portals: BASE.gov, TED/JOUE, OJEU, NATO portals, DIO UK
- Research competitor companies: products, pricing, news, contacts
- Find and compile contact directories (agents, shipyards, brokers, classification societies)
- Track vessel movements, port calls, AIS data
- Search for technical specifications, datasheets, manuals
- Find naval repair yards, drydock availability and rates

📋 HOW YOU WORK:
1. Understand the task in natural language
2. Plan: which sources to check, what to search for
3. Search systematically and cross-reference results
4. Return structured, actionable results with source URLs

IMPORTANT RULES:
- NEVER enter passwords or sensitive credentials
- NEVER make purchases or financial transactions
- Always cite sources (URLs) for information found
- Be specific — give part numbers, exact prices, company names, emails, phone numbers
- If a search returns no results, say so and suggest alternatives
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
