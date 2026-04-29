<?php

namespace App\Services\Robotparts;

use App\Models\RobotResearchReport;
use App\Services\AgentCatalog;
use App\Services\AgentSwarm\AgentDispatcher;
use App\Services\WebSearchService;
use Illuminate\Support\Facades\Log;

/**
 * Phase B — agents cooperate to research robot improvements.
 *
 * One session:
 *   1. Pick a topic from the catalogue (or operator-supplied)
 *   2. Pick a lead agent + 3 participants (different personas)
 *   3. For each participant:
 *        a. Tavily web search from their persona angle
 *        b. LLM call to write findings (3-5 bullets in PT-pt)
 *   4. Lead agent reads everyone's findings + writes:
 *        a. final_summary (markdown)
 *        b. proposals (JSON array of actionable items)
 *
 * Persistence: ONE robot_research_reports row per session, with the
 * full audit trail (each agent's findings, the cost, the topic).
 *
 * Failure mode: ALL participant dispatches can fail and the session
 * still records what's available. Lead failure → cancelled status,
 * partial findings still readable in the timeline.
 */
class ResearchCouncilService
{
    /**
     * Default topic catalogue. The cron picks one round-robin from
     * this list. Operator can call run(topic: '...') to override.
     */
    public const TOPICS = [
        'Como melhorar a eficiência energética do robot',
        'Alternativas mais baratas para os actuadores actuais',
        'Sensores que melhorariam a navegação autónoma',
        'Materiais alternativos para o chassis (PLA vs alumínio vs POM)',
        'Capacidades em falta no robot que valeria adicionar',
        'Patentes e prior art relevantes para o design modular',
        'Optimização do peso total e centro de gravidade',
        'Conectividade segura entre o robot e a swarm de agentes',
    ];

    /** Agents excluded from research panels (meta-agents). */
    private const META_AGENTS = ['orchestrator', 'auto'];

    public function __construct(
        private AgentDispatcher $dispatcher,
        private WebSearchService $webSearch,
    ) {}

    /**
     * Run one research session. Returns the persisted report (which
     * may be in 'complete' or 'cancelled' status).
     */
    public function run(?string $topic = null, ?string $leadingAgent = null): RobotResearchReport
    {
        $topic = $topic ?: self::TOPICS[array_rand(self::TOPICS)];

        $allAgents = collect(AgentCatalog::all())
            ->pluck('key')
            ->reject(fn($k) => in_array($k, self::META_AGENTS, true))
            ->values()
            ->shuffle()
            ->values();

        // Lead agent: caller-specified or first random.
        $lead = $leadingAgent ?? $allAgents->first();
        $participants = $allAgents
            ->reject(fn($k) => $k === $lead)
            ->take(3)
            ->prepend($lead)
            ->values()
            ->all();

        $report = RobotResearchReport::create([
            'topic'         => $topic,
            'status'        => RobotResearchReport::STATUS_RUNNING,
            'leading_agent' => $lead,
            'participants'  => $participants,
        ]);

        try {
            $totalCost = 0.0;

            // Each participant (incl. lead) does a web search + writes findings.
            $findings = [];
            foreach ($participants as $agentKey) {
                $entry = $this->participantFindings($agentKey, $topic, $totalCost);
                if ($entry !== null) {
                    $findings[] = $entry;
                }
            }
            $report->findings = $findings;
            $report->save();

            // Lead synthesises everyone's findings into final summary + proposals.
            $synthesis = $this->leadSynthesis($lead, $topic, $findings, $totalCost);

            if ($synthesis === null) {
                // No synthesis — keep partial findings, mark cancelled.
                $report->status = RobotResearchReport::STATUS_CANCELLED;
                $report->total_cost_usd = round($totalCost, 4);
                $report->save();
                return $report;
            }

            $report->final_summary = $synthesis['summary'];
            $report->proposals     = $synthesis['proposals'];
            $report->status        = RobotResearchReport::STATUS_COMPLETE;
            $report->total_cost_usd = round($totalCost, 4);
            $report->completed_at  = now();
            $report->save();

            return $report;
        } catch (\Throwable $e) {
            Log::error('ResearchCouncilService: crashed', [
                'report_id' => $report->id,
                'topic'     => $topic,
                'error'     => $e->getMessage(),
            ]);
            $report->status = RobotResearchReport::STATUS_CANCELLED;
            $report->save();
            return $report;
        }
    }

    /**
     * One participant's contribution: search + analyse + write findings.
     */
    private function participantFindings(string $agentKey, string $topic, float &$totalCost): ?array
    {
        $meta = AgentCatalog::find($agentKey);
        if (!$meta) return null;

        // Search query crafted from the agent's persona angle on the topic.
        $searchQuery = "robotics " . substr($topic, 0, 80);
        $searchText = $this->webSearch->isAvailable()
            ? $this->webSearch->search($searchQuery, maxResults: 5)
            : '(no web search available)';

        $system = "You are {$meta['name']} ({$meta['role']}). "
                . "The robot research council is investigating: \"{$topic}\". "
                . "Read the web search results and write YOUR FINDINGS from the angle of your persona. "
                . "Output Portuguese (PT-pt) markdown with 3-5 bullet points. "
                . "Each bullet should be a concrete observation or recommendation. "
                . "If the topic is outside your expertise, say so honestly in 1 line.";

        $user = "Topic: {$topic}\n\n"
              . "Web search results:\n{$searchText}\n\n"
              . "Write your findings.";

        $res = $this->dispatcher->dispatch($system, $user, maxTokens: 600);
        $totalCost += (float) ($res['cost_usd'] ?? 0);
        if (!($res['ok'] ?? false)) return null;

        return [
            'agent_key'           => $agentKey,
            'persona_angle'       => $meta['role'] ?? '',
            'search_query'        => $searchQuery,
            'search_text_snippet' => mb_substr($searchText, 0, 1500),
            'findings_md'         => trim((string) $res['text']),
            'at'                  => now()->toIso8601String(),
        ];
    }

    /**
     * Lead agent reads everyone's findings + writes the final
     * synthesis (markdown) + structured proposals.
     */
    private function leadSynthesis(string $leadKey, string $topic, array $findings, float &$totalCost): ?array
    {
        $leadMeta = AgentCatalog::find($leadKey);
        if (!$leadMeta) return null;

        $system = "You are {$leadMeta['name']} ({$leadMeta['role']}), leading a robot research council. "
                . "Your colleagues each researched and wrote findings on: \"{$topic}\". "
                . "Read all of them, then output STRICT JSON only:\n\n"
                . '{ "summary": "<200-400 word markdown final synthesis in PT-pt — what the council collectively concludes>", '
                . '"proposals": [ { "kind": "swap|add_slot|budget_bump|persona_change|note", '
                . '"target": "<slot_key | agent_key | null>", '
                . '"suggestion": "<1 sentence actionable in PT-pt>" } ] }' . "\n\n"
                . "kind explanations:\n"
                . "  swap            = replace an existing part with a better one\n"
                . "  add_slot        = identify a missing capability\n"
                . "  budget_bump     = an agent needs more budget for their slot\n"
                . "  persona_change  = an agent's domain should adjust\n"
                . "  note            = generic observation worth recording\n"
                . "Aim for 2-4 proposals.";

        $context = '';
        foreach ($findings as $f) {
            $name = AgentCatalog::find($f['agent_key'])['name'] ?? $f['agent_key'];
            $context .= "── {$name} ({$f['persona_angle']}) ──\n{$f['findings_md']}\n\n";
        }

        $res = $this->dispatcher->dispatch($system, "Topic: {$topic}\n\nFindings:\n{$context}\n\nNow synthesise. JSON only.", maxTokens: 1500);
        $totalCost += (float) ($res['cost_usd'] ?? 0);
        if (!($res['ok'] ?? false)) return null;

        $parsed = $this->parseJson((string) $res['text']);
        if ($parsed === null || empty($parsed['summary'])) return null;

        return [
            'summary'   => (string) $parsed['summary'],
            'proposals' => is_array($parsed['proposals'] ?? null) ? $parsed['proposals'] : [],
        ];
    }

    private function parseJson(string $text): ?array
    {
        $clean = trim($text);
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $clean, $m)) {
            $clean = trim($m[1]);
        }
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $data = json_decode(substr($clean, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
