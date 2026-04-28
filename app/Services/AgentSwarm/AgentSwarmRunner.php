<?php

namespace App\Services\AgentSwarm;

use App\Models\AgentSwarmRun;
use App\Models\LeadOpportunity;
use App\Services\HpHistoryClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The orchestrator. Takes a signal (tender, email, manual query),
 * picks a chain, runs the phases, and persists the result as one or
 * more LeadOpportunity rows tied to the AgentSwarmRun audit.
 *
 * Boundaries:
 *   • Idempotent on signal_hash — re-running for the same signal
 *     reuses the existing run row (returns it without re-firing).
 *   • Per-run budget cap: aborts gracefully when cost_usd exceeds
 *     `max_cost_per_run`, marking the run as 'aborted' with a
 *     reason so the admin sees WHY in the chain log.
 *   • Per-day budget cap: checked before starting; if today's spend
 *     across all runs already exceeds `max_cost_per_day`, the new
 *     run is created in pending and immediately aborted.
 *   • Failure isolation: a single agent throwing doesn't crash the
 *     whole chain — we record the error in chain_log and proceed
 *     to the next agent. The synthesiser sees what data IS
 *     available and can still produce a (lower-confidence) lead.
 *
 * What this skeleton doesn't yet do (deferred to B2):
 *   • Actually call agents. Right now `callAgent()` is a stub that
 *     returns a deterministic placeholder so the integration tests
 *     can pin the orchestration shape WITHOUT spending tokens.
 *   • Real synthesis. The default synthesiser produces a low-score
 *     "skeleton lead" so the UI has something to render.
 *
 * B2 will replace `callAgent()` with the real Anthropic dispatch
 * via AgentManager, plumbing prior context into each agent's
 * augmentMessage path.
 */
class AgentSwarmRunner
{
    /** Default per-run budget — overridable per call. */
    private float $maxCostPerRun;
    /** Daily aggregate budget; checked at run start. */
    private float $maxCostPerDay;

    /** B2: real LLM dispatcher + prompt composer. */
    private AgentDispatcher $dispatcher;
    private PromptBuilder $prompts;

    /**
     * Both dispatcher + prompts are injected (defaults via container)
     * so tests can swap a fake dispatcher in. The budget knobs stay
     * scalar so callers can still override at run time without
     * touching the container.
     */
    public function __construct(
        ?float $maxCostPerRun = null,
        ?float $maxCostPerDay = null,
        ?AgentDispatcher $dispatcher = null,
        ?PromptBuilder $prompts = null,
    ) {
        $this->maxCostPerRun = $maxCostPerRun ?? (float) config('services.agent_swarm.max_cost_per_run', 0.10);
        $this->maxCostPerDay = $maxCostPerDay ?? (float) config('services.agent_swarm.max_cost_per_day', 5.00);
        $this->dispatcher    = $dispatcher    ?? app(AgentDispatcher::class);
        $this->prompts       = $prompts       ?? app(PromptBuilder::class);
    }

    /**
     * Public entry point. Idempotent — re-calling with the same
     * signal hash returns the existing run.
     *
     * @param string $signalType         e.g. 'tender', 'email'
     * @param string|null $signalId      identifier within that type (tender id, …)
     * @param array $signalPayload       arbitrary context passed to phase 1
     * @param string|null $chainName     defaults to chain matching signal_type
     * @param int|null $triggeredByUser  user id of the manual trigger, null for cron
     */
    public function run(
        string $signalType,
        ?string $signalId,
        array $signalPayload = [],
        ?string $chainName = null,
        ?int $triggeredByUser = null,
    ): AgentSwarmRun {
        $hash = AgentSwarmRun::hashFor($signalType, $signalId);

        // Idempotency — already processed this signal once.
        if ($existing = AgentSwarmRun::where('signal_hash', $hash)->first()) {
            return $existing;
        }

        $chainName = $chainName ?? $this->defaultChainFor($signalType);
        $chain     = ChainSpec::get($chainName);

        $run = AgentSwarmRun::create([
            'signal_type'          => $signalType,
            'signal_id'            => $signalId,
            'signal_hash'          => $hash,
            'signal_payload'       => $signalPayload,
            'chain_name'           => $chainName,
            'status'               => AgentSwarmRun::STATUS_PENDING,
            'triggered_by_user_id' => $triggeredByUser,
        ]);

        if ($chain === null) {
            return $run->markFailed("unknown chain: {$chainName}");
        }

        // Daily cap pre-flight.
        if ($this->todaySpend() >= $this->maxCostPerDay) {
            return $run->markAborted('daily_budget_exceeded');
        }

        $run->markRunning();

        try {
            $context = ['signal' => $signalPayload];
            foreach ($chain as $idx => [$phaseType, $agents]) {
                if ($run->cost_usd >= $this->maxCostPerRun) {
                    $run->markAborted('per_run_budget_exceeded');
                    return $run;
                }
                $context = $this->runPhase($run, $idx, $phaseType, $agents, $context);
            }

            $this->persistLeads($run, $context);
            $run->markDone();
            return $run;
        } catch (Throwable $e) {
            Log::error('AgentSwarmRunner: chain crashed', [
                'run_id'      => $run->id,
                'chain_name'  => $chainName,
                'signal_type' => $signalType,
                'signal_id'   => $signalId,
                'error'       => $e->getMessage(),
                'trace'       => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            $run->markFailed($e->getMessage());
            return $run;
        }
    }

    /**
     * Execute one phase. Returns the updated context dict that
     * accumulates everything the next phase will see.
     */
    protected function runPhase(AgentSwarmRun $run, int $idx, string $type, array $agents, array $context): array
    {
        $phaseLabel = $type . '#' . $idx;

        switch ($type) {
            case 'parallel':
            case 'sequential':
                // For B1 the two are functionally identical because
                // callAgent() doesn't actually concurrent-run. B2 will
                // replace 'parallel' with a Guzzle pool / Concurrency
                // facade so multi-agent fan-out is real.
                foreach ($agents as $agentKey) {
                    $context[$agentKey] = $this->callAgent($run, $phaseLabel, $agentKey, $context);
                }
                return $context;

            case 'history':
                $context['hp_history'] = $this->callHistory($run, $phaseLabel, $context);
                return $context;

            case 'synthesize':
                // The first agent in the synthesis phase is the
                // "voice" — we ask it to produce the final lead.
                $key = $agents[0] ?? 'sales';
                $context['_synthesis'] = $this->callAgent($run, $phaseLabel, $key, $context, isSynthesis: true);
                return $context;
        }

        $run->appendStep([
            'event'  => 'unknown_phase',
            'phase'  => $phaseLabel,
            'reason' => "unknown phase type: {$type}",
        ]);
        return $context;
    }

    /**
     * B2: real Anthropic dispatch via AgentDispatcher.
     *
     * Builds the per-agent system + user prompts via PromptBuilder,
     * fires one Messages call, records token usage + USD cost in the
     * chain_log step, and returns the agent's output for downstream
     * phases.
     *
     * Failure isolation: if the dispatcher returns ok=false (5xx,
     * 4xx, transport, JSON decode) we record the error in chain_log
     * with a synthetic-zero cost and return an empty-output marker
     * so the next agent + the synthesiser still run with whatever
     * data IS available. The chain only ABORTS when the per-run
     * budget cap fires (handled by the caller via run_phase).
     *
     * For the synthesis pass we additionally parse the assistant text
     * into the {leads:[…]} array shape that persistLeads() expects.
     * If parsing fails we fall back to stubSynthesis() so the run
     * still produces SOMETHING for the operator to triage.
     */
    protected function callAgent(
        AgentSwarmRun $run,
        string $phaseLabel,
        string $agentKey,
        array $context,
        bool $isSynthesis = false,
    ): array {
        $signal = $context['signal'] ?? [];
        $system = $this->prompts->systemFor($agentKey, $isSynthesis);
        $user   = $isSynthesis
            ? $this->prompts->synthesisUserFor($signal, $context)
            : $this->prompts->userFor($agentKey, $signal, $context);

        // Synthesis gets a higher max_tokens so it has room for the
        // full JSON object even when the chain found multiple leads.
        $maxTokens = $isSynthesis ? 2500 : 1200;

        $res = $this->dispatcher->dispatch($system, $user, $maxTokens);

        $step = [
            'event'      => 'agent_call',
            'phase'      => $phaseLabel,
            'agent'      => $agentKey,
            'model'      => $res['model']      ?? null,
            'tokens_in'  => (int) ($res['tokens_in']  ?? 0),
            'tokens_out' => (int) ($res['tokens_out'] ?? 0),
            'cost_usd'   => (float) ($res['cost_usd'] ?? 0.0),
            'ms'         => (int) ($res['ms'] ?? 0),
            'ok'         => (bool) ($res['ok'] ?? false),
        ];

        if (!($res['ok'] ?? false)) {
            $step['error'] = $res['error'] ?? 'unknown';
            $output = $isSynthesis
                ? $this->stubSynthesis($context, fallbackReason: 'dispatch_failed:' . ($res['error'] ?? '?'))
                : ['agent' => $agentKey, 'error' => $step['error'], 'text' => ''];
            $step['output'] = $output;
            $run->appendStep($step);
            return $output;
        }

        $text = (string) ($res['text'] ?? '');

        if ($isSynthesis) {
            $parsed = $this->prompts->parseSynthesis($text);
            if (!empty($parsed['parse_error'])) {
                // Couldn't parse the model's JSON — record but keep
                // the run usable via stub synthesis.
                $step['parse_error'] = $parsed['parse_error'];
                $step['raw_text']    = mb_substr($text, 0, 500);
                $output = $this->stubSynthesis($context, fallbackReason: 'synth_parse_failed:' . $parsed['parse_error']);
            } else {
                $output = ['leads' => $parsed['leads']];
            }
        } else {
            $output = [
                'agent' => $agentKey,
                'text'  => $text,
            ];
        }

        $step['output'] = $output;
        $run->appendStep($step);
        return $output;
    }

    /**
     * Call hp-history if the client is enabled. Silently no-op
     * otherwise — chains continue to work without the precedent
     * lookup, just with less context.
     */
    protected function callHistory(AgentSwarmRun $run, string $phaseLabel, array $context): array
    {
        $client = app(HpHistoryClient::class);
        if (!$client->isEnabled()) {
            $run->appendStep([
                'event'  => 'history_skipped',
                'phase'  => $phaseLabel,
                'reason' => 'hp_history disabled',
            ]);
            return [];
        }

        $query = (string) ($context['signal']['query']
            ?? $context['signal']['title']
            ?? $context['signal']['reference']
            ?? '');
        if ($query === '') {
            $run->appendStep(['event' => 'history_skipped', 'phase' => $phaseLabel, 'reason' => 'empty_query']);
            return [];
        }

        $hits = $client->search($query);
        $run->appendStep([
            'event'    => 'history_search',
            'phase'    => $phaseLabel,
            'query'    => $query,
            'hits'     => count($hits),
            'cost_usd' => 0,    // hp-history server bills its own embeddings
        ]);
        return ['hits' => $hits];
    }

    /**
     * Fallback synthesiser when the LLM dispatch failed or returned
     * unparseable text. Produces ONE low-score lead so the operator
     * still sees something in /leads — the run is flagged via
     * chain_log so they know it was a degraded synthesis.
     *
     * The score is intentionally CAPPED at 50 here (vs 85 in the
     * old stub) — a fallback lead must never look high-conviction.
     */
    protected function stubSynthesis(array $context, string $fallbackReason = 'no_llm'): array
    {
        $agentsRan = 0;
        foreach ($context as $k => $_) {
            if ($k !== 'signal' && $k !== '_synthesis') $agentsRan++;
        }
        $score = min(50, 15 + $agentsRan * 7);
        return [
            'leads' => [[
                'title'    => 'Lead: ' . ($context['signal']['title'] ?? '(unknown signal)'),
                'summary'  => 'Fallback synthesis (' . $fallbackReason . ') from '
                              . $agentsRan . ' agent step(s). Manual review needed.',
                'score'    => $score,
                'fallback' => true,
            ]],
        ];
    }

    /**
     * Take the synthesiser's `leads` list and persist each as a
     * LeadOpportunity tied to this run.
     */
    protected function persistLeads(AgentSwarmRun $run, array $context): void
    {
        $synth = $context['_synthesis'] ?? [];
        $leads = $synth['leads'] ?? [];
        foreach ($leads as $lead) {
            $score = (int) ($lead['score'] ?? 0);
            LeadOpportunity::create([
                'swarm_run_id'      => $run->id,
                'title'             => mb_substr((string) ($lead['title'] ?? '(untitled)'), 0, 255),
                'summary'           => (string) ($lead['summary'] ?? ''),
                'score'             => max(0, min(100, $score)),
                'customer_hint'     => $lead['customer_hint'] ?? null,
                'equipment_hint'    => $lead['equipment_hint'] ?? null,
                'source_signal_type'=> $run->signal_type,
                'source_signal_id'  => $run->signal_id,
                'status'            => LeadOpportunity::statusForScore($score),
            ]);
        }
    }

    /** Sum of cost_usd across all swarm_runs that started today. */
    protected function todaySpend(): float
    {
        return (float) AgentSwarmRun::query()
            ->whereDate('created_at', today())
            ->sum('cost_usd');
    }

    protected function defaultChainFor(string $signalType): string
    {
        return match ($signalType) {
            'tender' => ChainSpec::TENDER_TO_LEAD,
            'email'  => ChainSpec::EMAIL_TO_LEAD,
            default  => ChainSpec::TENDER_TO_LEAD,
        };
    }
}
