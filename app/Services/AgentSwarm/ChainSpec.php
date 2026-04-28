<?php

namespace App\Services\AgentSwarm;

/**
 * Declarative chain spec — what agents run, in what order, and how
 * they fan out / combine.
 *
 * A chain is a list of phases. Each phase is one of:
 *   ['parallel',    [agentKey, agentKey, …]]   — fan out, collect all
 *   ['sequential',  [agentKey, agentKey, …]]   — run one after another
 *   ['history',     []]                         — call hp-history (skipped if disabled)
 *   ['synthesize',  [agentKey]]                 — final pass that gets all prior context
 *
 * The orchestrator (AgentSwarmRunner) walks the phases, accumulating
 * each agent's output into a context blob that the next phase sees.
 *
 * Why declarative: we want non-engineers (admins) to be able to tweak
 * which agents run for which signal type without touching PHP. A
 * future iteration can persist these specs in DB and let admins edit
 * via UI; today they live in this file as readable PHP.
 *
 * Why these specific chains: each models a real internal workflow:
 *
 *   tender_to_lead    Marina (market) + Vasco (vessel) + Marta (CRM)
 *                     in parallel, then hp-history for precedent,
 *                     then Marco synthesises the sales angle.
 *
 *   email_to_lead     Daniel parses the email first (sequential —
 *                     subsequent agents need the parsed structure),
 *                     Marta cross-refs the customer, Marco pitches.
 *
 *   equipment_research Sofia (IP) + Victor (R&D) + Marina (market)
 *                     in parallel for the future-equipment angle,
 *                     synthesised by the engineering agent.
 */
class ChainSpec
{
    public const TENDER_TO_LEAD = 'tender_to_lead';
    public const EMAIL_TO_LEAD  = 'email_to_lead';
    public const EQUIPMENT_RESEARCH = 'equipment_research';

    /**
     * Map of chain name → ordered list of phases.
     *
     * @return array<string, array<int, array{0:string,1:array<int,string>}>>
     */
    public static function all(): array
    {
        return [
            self::TENDER_TO_LEAD => [
                ['parallel',   ['research', 'vessel', 'crm']],
                ['history',    []],
                ['synthesize', ['sales']],
            ],
            self::EMAIL_TO_LEAD => [
                ['sequential', ['email']],          // parse first
                ['parallel',   ['crm', 'research']],
                ['history',    []],
                ['synthesize', ['sales']],
            ],
            self::EQUIPMENT_RESEARCH => [
                ['parallel',   ['patent', 'engineer', 'research']],
                ['synthesize', ['engineer']],       // R&D self-synthesises
            ],
        ];
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** All known chain names — for validation in the controller. */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Flat list of every agent key referenced across all chains. Used
     * by the cost-estimation pre-flight: we count how many agents will
     * fire BEFORE we charge to abort runs that would exceed the budget
     * even on the cheapest call.
     *
     * @return array<int, string>
     */
    public static function uniqueAgentKeys(): array
    {
        $keys = [];
        foreach (self::all() as $phases) {
            foreach ($phases as [$type, $agents]) {
                if ($type === 'history') continue;
                foreach ($agents as $a) $keys[$a] = true;
            }
        }
        return array_keys($keys);
    }
}
