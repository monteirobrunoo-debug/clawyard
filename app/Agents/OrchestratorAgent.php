<?php

namespace App\Agents;

use GuzzleHttp\Client;
use App\Agents\Traits\AnthropicKeyTrait;
use App\Agents\Traits\HandlesAnthropicStream;
use App\Agents\Traits\SharedContextTrait;
use App\Services\PartYardProfileService;
use App\Services\PromptLibrary;
use App\Services\SharedContextService;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\PromiseInterface;

class OrchestratorAgent implements AgentInterface
{
    use AnthropicKeyTrait;
    use HandlesAnthropicStream;
    use SharedContextTrait;
    protected string $systemPrompt = '';

    // HDPO meta-cognitive search gate: 'always' | 'conditional' | 'never'
    protected string $searchPolicy = 'never';
    protected Client $client;
    protected array $agents;

    // F3: Maestro publica a síntese no bus de contexto partilhado
    protected string $contextKey  = 'maestro_intel';
    protected array  $contextTags = ['maestro','síntese','multi-agente','cross-domain'];

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
- shipping: Logística/PartYard — UPS/FedEx quotes, invoice cataloguing, customs (Incoterms, TARIC, VIES, DAU/SAD, IVA intra-UE)

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
            'base_uri'        => self::getAnthropicBaseUri(),
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

        $messageText = is_array($message) ? ($message[0]['text'] ?? '') : $message;

        // F3: publicar respostas dos sub-agentes no bus de contexto partilhado
        foreach ($results as $r) {
            if (($r['reply'] ?? '') !== '') {
                $this->publishAgentFinding($r['agent'], $r['reply']);
            }
        }

        $combined = $this->combineResults($messageText, $results);
        $this->publishSharedContext($combined);
        return $combined;
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
     * Maestro F2 — stream com síntese unificada quando há múltiplos agentes.
     *
     * Single agent  → stream directo (comportamento F1, zero overhead).
     * Multi-agent   → recolhe respostas em paralelo via Fibers + chat(),
     *                 depois stream da síntese Haiku numa resposta coesa.
     *                 O user vê UMA resposta integrada, não N secções separadas.
     */
    public function stream(string|array $message, array $history, callable $onChunk, ?callable $heartbeat = null): string
    {
        $messageText = is_array($message) ? ($message[0]['text'] ?? '') : $message;

        if ($heartbeat) $heartbeat('a escolher os agentes certos');
        $agentNames = $this->decideAgents($messageText);

        $agentNames = array_values(array_filter($agentNames, fn($n) => isset($this->agents[$n])));

        if (empty($agentNames)) {
            $onChunk("⚠️ Não consegui identificar o agente certo para este pedido.\n");
            return '';
        }

        $labels = $this->agentLabels();

        // ── Single agent: stream directo, sem síntese ──────────────────────
        if (count($agentNames) === 1) {
            $name   = $agentNames[0];
            $header = "🔀 **A activar:** " . ($labels[$name] ?? $name) . "\n\n";
            $onChunk($header);
            $combined = $header;

            if ($heartbeat) $heartbeat("a consultar {$name}");
            $agent = $this->agents[$name];

            try {
                $text = $agent->stream(
                    $message,
                    $history,
                    function (string $chunk) use (&$combined, $onChunk) {
                        $combined .= $chunk;
                        $onChunk($chunk);
                    },
                    $heartbeat,
                );
                if (is_string($text) && $text !== '' && !str_contains($combined, $text)) {
                    $onChunk($text);
                    $combined .= $text;
                }
            } catch (\Throwable $e) {
                $msg = "\n❌ Erro: " . $e->getMessage() . "\n";
                $onChunk($msg);
                $combined .= $msg;
            }
            return $combined;
        }

        // ── Multi-agent F2: recolha paralela + síntese ─────────────────────
        $routed = array_map(fn($n) => $labels[$n] ?? $n, $agentNames);
        $header = "🎼 **Maestro a coordenar:** " . implode(' · ', $routed) . "…\n\n";
        $onChunk($header);

        if ($heartbeat) $heartbeat('a recolher análises dos especialistas…');

        $agentResponses = $this->collectAgentsParallel($message, $history, $agentNames);

        if ($heartbeat) $heartbeat('a sintetizar resposta…');

        $synthesis = $this->streamSynthesis($messageText, $agentResponses, $onChunk, $heartbeat);

        // F3: publicar síntese no bus → próximos turnos e agentes vêem o que foi discutido
        $this->publishSharedContext($synthesis);

        return $header . $synthesis;
    }

    /**
     * Recolhe respostas de múltiplos agentes usando Fibers (melhor esforço paralelo).
     */
    private function collectAgentsParallel(string|array $message, array $history, array $agentNames): array
    {
        $results = [];
        $fibers  = [];

        foreach ($agentNames as $name) {
            if (!isset($this->agents[$name])) continue;
            $fiber = new \Fiber(function () use ($name, $message, $history) {
                try {
                    return ['agent' => $name, 'reply' => $this->agents[$name]->chat($message, $history)];
                } catch (\Throwable $e) {
                    return ['agent' => $name, 'reply' => ''];
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

        // Fallback para fibras que não terminaram
        foreach ($agentNames as $name) {
            if (!isset($this->agents[$name])) continue;
            if (!empty(array_filter($results, fn($r) => $r['agent'] === $name))) continue;
            try {
                $results[] = ['agent' => $name, 'reply' => $this->agents[$name]->chat($message, $history)];
            } catch (\Throwable $e) {
                $results[] = ['agent' => $name, 'reply' => ''];
            }
        }

        // F3: publicar cada resposta no bus — agentes sem auto-publicação ficam registados
        foreach ($results as $r) {
            if (($r['reply'] ?? '') !== '') {
                $this->publishAgentFinding($r['agent'], $r['reply']);
            }
        }

        return $results;
    }

    /**
     * F3: Publica a resposta de um sub-agente no bus de contexto partilhado,
     * sob a chave e nome próprios do agente. Permite que agentes sem
     * auto-publicação (contextKey vazio) deixem memória de sessão.
     */
    private function publishAgentFinding(string $agentKey, string $text): void
    {
        $labels = $this->agentLabels();
        // Remove o emoji do início: "💼 Marco — Sales" → "Marco — Sales"
        $name = preg_replace('/^[^\s\w]+\s*/u', '', $labels[$agentKey] ?? ucfirst($agentKey));
        (new SharedContextService())->publish(
            $agentKey,
            $name,
            $agentKey . '_intel',
            $text,
            [],
            $this->sharedContextUserId(),
        );
    }

    /**
     * Envia as respostas dos especialistas a Haiku e stream a síntese unificada.
     */
    private function streamSynthesis(string $question, array $agentResponses, callable $onChunk, ?callable $heartbeat): string
    {
        $labels = $this->agentLabels();

        $blocks = [];
        foreach ($agentResponses as $r) {
            if (($r['reply'] ?? '') === '') continue;
            $label    = $labels[$r['agent']] ?? ucfirst($r['agent']);
            $blocks[] = "### {$label}\n{$r['reply']}";
        }

        if (empty($blocks)) {
            $msg = "⚠️ Nenhum especialista conseguiu responder.\n";
            $onChunk($msg);
            return $msg;
        }

        $expertsBlock = implode("\n\n", $blocks);

        $systemPrompt = <<<MAESTRO
És o Maestro ClawYard — o coordenador sénior de uma equipa de especialistas de IA.
Recebeste as análises independentes de vários especialistas sobre a pergunta do utilizador.
Produz UMA resposta coesa, directa e completa que integre o melhor de cada perspectiva.
Não uses introduções como "De acordo com Marco..." ou "O especialista X diz...".
Escreve como um único consultor sénior experiente, em português de Portugal.
Usa markdown (negrito, listas, tabelas) quando adequado. Sê conciso mas completo.
MAESTRO;

        $userPrompt = "Pergunta original: {$question}\n\nAnálises dos especialistas:\n{$expertsBlock}\n\nSintetiza uma resposta unificada.";

        return $this->streamAnthropicWithRetries(
            config: [
                'model'      => 'claude-haiku-4-6',
                'max_tokens' => 2048,
                'stream'     => true,
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userPrompt]],
            ],
            headers:          $this->apiHeaders(),
            onChunk:          $onChunk,
            heartbeat:        $heartbeat,
            heartbeatLabel:   'Maestro a sintetizar…',
            retries:          [0, 2, 5],
            emergencyMessage: "\n⚠️ Maestro: erro na síntese. Tenta reformular a pergunta.\n",
            agentLabel:       'Maestro',
        );
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
            'shipping'     => '🚚 Logística/PartYard',
        ];
    }

    public function getName(): string { return 'orchestrator'; }
    public function getModel(): string { return 'multi-agent'; }
}
