<?php

namespace App\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\PromiseInterface;

class OrchestratorAgent implements AgentInterface
{
    protected Client $client;
    protected array $agents;

    protected string $systemPrompt = <<<PROMPT
You are an AI orchestrator that analyzes user messages and decides which specialist agents should handle them.

Available agents:
- sales: product pricing, quotes, purchase inquiries
- support: technical problems, troubleshooting, repairs
- email: drafting and writing emails
- sap: SAP B1 queries, stock, orders, invoices
- document: PDF analysis, contracts, technical specs
- maritime: maritime vessels, ship parts, certificates
- stock: inventory, parts availability, warehouse
- claude: complex reasoning, analysis, general questions
- nvidia: fast responses, simple queries

Respond ONLY with a JSON array of agent names to activate. Example:
["sales", "stock", "email"]

Always select the minimum agents needed. Maximum 4 agents at once.
PROMPT;

    public function __construct(array $agents = [])
    {
        $this->agents = $agents;
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'headers'  => [
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
        ]);
    }

    /**
     * Decide which agents to activate for a given message.
     */
    public function decideAgents(string $message): array
    {
        try {
            $response = $this->client->post('/v1/messages', [
                'json' => [
                    'model'      => 'claude-haiku-20240307',
                    'max_tokens' => 100,
                    'system'     => $this->systemPrompt,
                    'messages'   => [
                        ['role' => 'user', 'content' => $message],
                    ],
                ],
            ]);

            $data   = json_decode($response->getBody()->getContents(), true);
            $text   = $data['content'][0]['text'] ?? '["claude"]';
            $agents = json_decode($text, true);

            return is_array($agents) ? $agents : ['claude'];
        } catch (\Exception $e) {
            return ['claude'];
        }
    }

    /**
     * Run multiple agents in parallel and combine their responses.
     */
    public function chat(string $message, array $history = []): string
    {
        $agentNames = $this->decideAgents($message);
        $results    = [];

        // Run agents (sequentially for now, can be parallelized with queues)
        foreach ($agentNames as $name) {
            if (isset($this->agents[$name])) {
                try {
                    $reply     = $this->agents[$name]->chat($message, $history);
                    $results[] = [
                        'agent' => $name,
                        'reply' => $reply,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'agent' => $name,
                        'reply' => "Error: " . $e->getMessage(),
                    ];
                }
            }
        }

        return $this->combineResults($message, $results);
    }

    /**
     * Combine multiple agent responses into one coherent reply.
     */
    protected function combineResults(string $originalMessage, array $results): string
    {
        if (count($results) === 1) {
            return $results[0]['reply'];
        }

        $combined = "# ClawYard Multi-Agent Response\n\n";

        $agentLabels = [
            'sales'     => '💼 Sales Agent',
            'support'   => '🔧 Support Agent',
            'email'     => '📧 Email Agent',
            'sap'       => '📊 SAP Agent',
            'document'  => '📄 Document Agent',
            'maritime'  => '🚢 Maritime Agent',
            'stock'     => '📦 Stock Agent',
            'claude'    => '🧠 Claude Agent',
            'nvidia'    => '⚡ NVIDIA Agent',
        ];

        foreach ($results as $result) {
            $label     = $agentLabels[$result['agent']] ?? ucfirst($result['agent']) . ' Agent';
            $combined .= "## {$label}\n\n";
            $combined .= $result['reply'] . "\n\n";
            $combined .= "---\n\n";
        }

        return $combined;
    }

    /**
     * Get which agents were activated for the last message.
     */
    public function getActivatedAgents(string $message): array
    {
        return $this->decideAgents($message);
    }

    /**
     * Orchestrator does not stream individual chunks — it runs all sub-agents to completion
     * and delivers the combined reply as a single chunk via the callback.
     */
    public function stream(string $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $reply = $this->chat($message, $history);
        $onChunk($reply);
        return $reply;
    }

    public function getName(): string { return 'orchestrator'; }
    public function getModel(): string { return 'multi-agent'; }
}
