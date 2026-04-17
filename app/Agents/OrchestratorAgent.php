<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\SharedContextTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\PromiseInterface;

class OrchestratorAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'never';
    protected Client $client;
    protected array $agents;

    public function __construct(array $agents = [])
    {
        $persona = 'You are an AI orchestrator that analyzes user messages and decides which specialist agents should handle them.';

        $specialty = <<<'SPECIALTY'
Available agents:
- sales: product pricing, quotes, purchase inquiries, spare parts availability
- support: technical problems, troubleshooting, engine repairs, diagnostics
- email: drafting and writing emails, templates, cold outreach
- sap: SAP B1 queries, stock levels, sales orders, purchase orders, invoices, business partners
- document: PDF analysis, contracts, technical specs, certificates, manuals
- claude: complex reasoning, analysis, strategy, general questions
- nvidia: fast responses, simple short queries
- quantum: scientific papers, arXiv research, patents, technology trends, innovation
- aria: cybersecurity, security audits, STRIDE, OWASP, vulnerability assessment
- briefing: executive daily briefing, intelligence summary, strategic overview
- research: website analysis, competitor benchmarking, market research, SEO, improvements
- finance: financial analysis, accounting, invoices, SAP financial data, ROC/TOC, fiscal compliance

Respond ONLY with a JSON array of agent names to activate. Example:
["sales", "sap"]

Always select the minimum agents needed. Maximum 3 agents at once.
SPECIALTY;

        $this->systemPrompt = str_replace(
            '[PROFILE_PLACEHOLDER]',
            PartYardProfileService::toPromptContext(),
            PromptLibrary::reasoning($persona, $specialty)
        );

        $this->agents = $agents;
        $this->client = new Client([
            'base_uri'        => 'https://api.anthropic.com',
            'timeout'         => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Decide which agents to activate for a given message.
     */
    public function decideAgents(string $message): array
    {
        try {
            $response = $this->client->post('/v1/messages', [
                'headers' => $this->apiHeaders(),
                'json'    => [
                    'model'      => 'claude-haiku-4-6',
                    // A JSON array like ["sap","sales","crm","finance"] can
                    // easily exceed 100 tokens once the model adds any
                    // framing. Truncation made json_decode fall back to
                    // ["claude"], silently losing multi-agent routing.
                    'max_tokens' => 256,
                    'system'     => $this->enrichSystemPrompt($this->systemPrompt),
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
     * Run multiple agents in parallel using PHP 8.1 Fibers and combine their responses.
     */
    public function chat(string|array $message, array $history = []): string
    {
        $agentNames = $this->decideAgents(is_array($message) ? ($message[0]['text'] ?? '') : $message);
        $results    = [];

        // Run agents in parallel using PHP 8.1 fibers when multiple agents selected
        if (count($agentNames) > 1) {
            $fibers = [];
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                $fiber = new \Fiber(function () use ($name, $message, $history) {
                    try {
                        return [
                            'agent' => $name,
                            'reply' => $this->agents[$name]->chat($message, $history),
                        ];
                    } catch (\Throwable $e) {
                        return ['agent' => $name, 'reply' => "Erro: " . $e->getMessage()];
                    }
                });
                $fibers[$name] = $fiber;
                $fiber->start();
            }
            foreach ($fibers as $name => $fiber) {
                if ($fiber->isTerminated()) {
                    $results[] = $fiber->getReturn();
                }
            }
            // Any fiber not terminated yet — run sequentially as fallback
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                $alreadyDone = array_filter($results, fn($r) => $r['agent'] === $name);
                if (!empty($alreadyDone)) continue;
                try {
                    $results[] = ['agent' => $name, 'reply' => $this->agents[$name]->chat($message, $history)];
                } catch (\Throwable $e) {
                    $results[] = ['agent' => $name, 'reply' => "Erro: " . $e->getMessage()];
                }
            }
        } else {
            // Single agent — no fiber overhead
            foreach ($agentNames as $name) {
                if (!isset($this->agents[$name])) continue;
                try {
                    $results[] = ['agent' => $name, 'reply' => $this->agents[$name]->chat($message, $history)];
                } catch (\Throwable $e) {
                    $results[] = ['agent' => $name, 'reply' => "Erro: " . $e->getMessage()];
                }
            }
        }

        return $this->combineResults(is_array($message) ? ($message[0]['text'] ?? '') : $message, $results);
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
            'sales'     => '💼 Marco — Sales',
            'support'   => '🔧 Marcus — Technical Support',
            'email'     => '📧 Daniel — Email',
            'sap'       => '📊 Richard — SAP B1',
            'document'  => '📄 Comandante Doc',
            'claude'    => '🧠 Bruno AI',
            'nvidia'    => '⚡ Carlos NVIDIA',
            'quantum'   => '⚛️ Prof. Quantum Leap',
            'aria'      => '🔐 ARIA — Security',
            'briefing'  => '📋 Renato — Briefing',
            'research'  => '🔍 Marina — Research',
            'finance'   => '💰 Dr. Luís — Finance',
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
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $reply = $this->chat($message, $history);
        $onChunk($reply);
        return $reply;
    }

    public function getName(): string { return 'orchestrator'; }
    public function getModel(): string { return 'multi-agent'; }
}
