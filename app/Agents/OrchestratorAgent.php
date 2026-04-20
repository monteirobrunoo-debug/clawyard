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
Available agents (pick the BEST 1-3 for the user's question):

COMMERCIAL / SALES
- sales:    product pricing, quotes, purchase inquiries, spare parts availability, MTU/CAT/MAK/MAN
- support:  technical problems, troubleshooting, engine repairs, diagnostics, fault codes
- email:    drafting/writing maritime emails, templates, cold outreach, client proposals
- crm:      SAP B1 Sales Opportunities, pipeline, creating opportunities from emails, vendedor assignment
- capitao:  port operations, port calls, vessel documentation, maritime logistics (Capitão Porto)

ERP / DATA
- sap:      SAP B1 queries — stock levels, sales/purchase orders, invoices, business partners, CardCodes
- finance:  accounting, ROC/TOC, audit, IFRS/SNC, IRC/IRS/IVA, budget, tax, consolidation, due diligence

DOCUMENTS / KNOWLEDGE
- document: PDF analysis, contracts, technical specs, certificates, manuals
- qnap:     company archive — search prices, codes, invoices, licences, contracts on QNAP

PUBLIC PROCUREMENT / DEFENCE
- acingov:  Portuguese public tenders (Acingov, base.gov.pt, TED, SAM.gov), NATO NSPA, EDA
- mildef:   military procurement, defence suppliers (excl. China/Russia), missiles, SAM, NATO/EDA/USLI
- vessel:   vessel search + naval repair, ship brokers, drydocks, IACS class, inland waterways
- patent:   IP validation, prior art EPO/USPTO/WIPO, freedom-to-operate, patentability, licensing

RESEARCH / STRATEGY
- quantum:  scientific papers (arXiv), patents (USPTO), technology trends, innovation scouting
- research: website analysis, competitor benchmarking, market research, SEO improvements
- engineer: R&D and product development — TRL plans, CAPEX, roadmap for new equipment
- energy:   maritime decarbonisation — Fuzzy TOPSIS, CII/EEXI, LNG/Biofuel/H2, fleet energy mgmt
- briefing: executive daily briefing, intelligence summary, strategic overview across all agents

SECURITY / ENCRYPTION
- aria:     cybersecurity, security audits, STRIDE, OWASP, vulnerability assessment, live site checks
- kyber:    post-quantum encryption (Kyber-1024), encrypted email, key generation

GENERAL / REASONING / AUTOMATION
- claude:   complex reasoning, analysis, strategy, general questions, default fallback
- nvidia:   fast answers for simple short queries
- thinking: extended thinking — multi-step reasoning, deep analysis, trade-off decisions
- batch:    bulk/batch processing — multiple items, lists, parallel tasks
- computer: web/desktop automation — browser control, form filling, portal navigation (RoboDesk)
- shipping: transport quotes — UPS/FedEx/DHL prices, zones, weight, dimensional, customs

ROUTING RULES:
1. Return ONLY a compact JSON array of agent NAMES (lowercase, exactly as listed above).
   Example: ["sap", "crm"]
2. Pick the MINIMUM number of agents needed. Maximum 3.
3. If the question is vague, default to: ["claude"]
4. Questions mixing topics can combine agents (e.g. "price + stock" → ["sales","sap"]).
5. NO prose, NO markdown, NO code fences — just the raw JSON array.
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
                    // framing. 256 leaves plenty of headroom.
                    'max_tokens' => 256,
                    'system'     => $this->enrichSystemPrompt($this->systemPrompt),
                    'messages'   => [
                        ['role' => 'user', 'content' => $message],
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $text = $data['content'][0]['text'] ?? '';

            $agents = $this->parseAgentList($text);
            $valid  = array_values(array_intersect($agents, array_keys($this->agents)));

            // If the parse returned nothing valid, fall back to Claude rather
            // than to an empty set (which would silently return nothing to
            // the user).
            return !empty($valid) ? $valid : ['claude'];
        } catch (\Exception $e) {
            return ['claude'];
        }
    }

    /**
     * Robustly parse an agent list out of the Haiku response.
     *
     * Accepts all of:
     *   ["sales","sap"]
     *   ```json
     *   ["sales"]
     *   ```
     *   Activating: ["sales"]
     *   sales, sap
     */
    private function parseAgentList(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];

        // 1. Direct JSON array
        $direct = json_decode($text, true);
        if (is_array($direct)) return $this->normaliseAgentNames($direct);

        // 2. First JSON array found anywhere in the text
        if (preg_match('/\[[^\[\]]*\]/', $text, $m)) {
            $slice = json_decode($m[0], true);
            if (is_array($slice)) return $this->normaliseAgentNames($slice);
        }

        // 3. Fallback: extract bare words and match against known agent keys
        $words = preg_split('/[^a-z]+/i', strtolower($text)) ?: [];
        return $this->normaliseAgentNames($words);
    }

    /**
     * Lowercase + trim + dedupe + cap at 3.
     */
    private function normaliseAgentNames(array $names): array
    {
        $names = array_map(
            fn($n) => strtolower(trim((string) $n)),
            $names
        );
        $names = array_values(array_unique(array_filter($names)));
        return array_slice($names, 0, 3);
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

        $agentLabels = $this->agentLabels();

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
     * Stream sub-agents progressively so the user sees each agent's
     * response as soon as it starts producing text. Much better UX than
     * waiting 60-90 s for all three agents to complete before any bytes
     * appear on the screen — and it prevents Cloudflare from cutting
     * the connection on slow multi-agent requests.
     */
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $messageText = is_array($message) ? ($message[0]['text'] ?? '') : $message;

        if ($heartbeat) $heartbeat('a escolher os agentes certos');
        $agentNames = $this->decideAgents($messageText);

        // Filter to agents we actually have registered.
        $agentNames = array_values(array_filter(
            $agentNames,
            fn($n) => isset($this->agents[$n])
        ));

        if (empty($agentNames)) {
            $onChunk("⚠️ Não consegui identificar o agente certo para este pedido.\n");
            return '';
        }

        // Announce routing decision to the user so they know what's happening.
        $labels  = $this->agentLabels();
        $routed  = array_map(fn($n) => $labels[$n] ?? $n, $agentNames);
        $header  = count($agentNames) === 1
            ? "🔀 **A activar:** " . $routed[0] . "\n\n"
            : "🔀 **A activar:** " . implode(' · ', $routed) . "\n\n";
        $onChunk($header);

        $combined = $header;

        foreach ($agentNames as $idx => $name) {
            if (!isset($this->agents[$name])) continue;
            $agent = $this->agents[$name];
            $label = $labels[$name] ?? ucfirst($name);

            // Per-agent section header (only when we have >1 agent)
            if (count($agentNames) > 1) {
                $sectionHeader = "\n## {$label}\n\n";
                $onChunk($sectionHeader);
                $combined .= $sectionHeader;
            }

            try {
                if ($heartbeat) $heartbeat("a consultar {$name}");

                // Delegate to the sub-agent's own streaming implementation so
                // tokens are relayed to the browser as they arrive.
                $text = $agent->stream(
                    $message,
                    $history,
                    function (string $chunk) use (&$combined, $onChunk) {
                        $combined .= $chunk;
                        $onChunk($chunk);
                    },
                    $heartbeat
                );

                // Defensive: some agents return the full text but never
                // invoked the $onChunk callback (e.g. when they detected
                // a special marker). Emit the returned text if the stream
                // callback didn't already cover it.
                if (is_string($text) && $text !== '' && !str_contains($combined, $text)) {
                    $onChunk($text);
                    $combined .= $text;
                }
            } catch (\Throwable $e) {
                $msg = "\n❌ Erro em {$label}: " . $e->getMessage() . "\n";
                $onChunk($msg);
                $combined .= $msg;
            }

            // Separator between agents
            if (count($agentNames) > 1 && $idx < count($agentNames) - 1) {
                $onChunk("\n\n---\n");
                $combined .= "\n\n---\n";
            }
        }

        return $combined;
    }

    /**
     * Canonical label map — keep in sync with AgentManager::$agents.
     */
    protected function agentLabels(): array
    {
        return [
            'sales'        => '💼 Marco — Sales',
            'support'      => '🔧 Marcus — Technical Support',
            'email'        => '📧 Daniel — Email',
            'sap'          => '📊 Richard — SAP B1',
            'crm'          => '🎯 Marta — CRM',
            'document'     => '📄 Comandante Doc',
            'capitao'      => '⚓ Capitão Porto',
            'claude'       => '🧠 Bruno AI',
            'nvidia'       => '⚡ Carlos NVIDIA',
            'quantum'      => '⚛️ Prof. Quantum Leap',
            'aria'         => '🔐 ARIA — Security',
            'briefing'     => '📋 Renato — Briefing',
            'research'     => '🔍 Marina — Research',
            'finance'      => '💰 Dr. Luís — Finance',
            'acingov'      => '🏛️ Dra. Ana — Concursos',
            'engineer'     => '🔩 Eng. Victor — I&D',
            'patent'       => '🏛️ Dra. Sofia — IP',
            'energy'       => '⚡ Eng. Sofia — Energia',
            'kyber'        => '🔒 KYBER — Encryption',
            'qnap'         => '🗄️ Arquivo PartYard',
            'thinking'     => '🧠 Prof. Deep Thought',
            'batch'        => '📦 Max Batch',
            'computer'     => '🖥️ RoboDesk',
            'vessel'       => '⚓ Capitão Vasco',
            'mildef'       => '🎖️ Cor. Rodrigues — Defesa',
            'shipping'     => '🚚 Tânia — Transportes',
        ];
    }

    public function getName(): string { return 'orchestrator'; }
    public function getModel(): string { return 'multi-agent'; }
}
