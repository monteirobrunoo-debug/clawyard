<?php

namespace App\Services;

use App\Models\PartnerWorkshop;
use Illuminate\Support\Collection;

/**
 * Read-only query layer over partner_workshops, designed to be called
 * from agent `augmentMessage` hooks. Two consumers today:
 *
 *   • SalesAgent (Marco)  → buildContextFor($message, 'spares')
 *   • VesselSearchAgent (Vasco) → buildContextFor($message, 'repair')
 *
 * Both use the same intent-detection: the agent doesn't have to know
 * how to call this service — just whether to call it. Inside, this
 * class:
 *
 *   1) Decides whether the user message is partner-related.
 *      (port name? service category? "drydock"/"engine"/"electrical"…?)
 *   2) Picks up to 6 partners ranked by:
 *        priority (high → info_only)
 *      → port/service relevance
 *      → name match
 *   3) Renders a compact MD block the agent can drop into context.
 *
 * Keeping the rendering here (not in the agents) means future tweaks
 * to format land in one place. The agents only know "did I get a
 * useful block back? if so, paste it under MY context".
 */
class PartnerWorkshopService
{
    /** Tokens that indicate the user is asking about port partners. */
    private const PARTNER_INTENT_TOKENS = [
        'porto', 'port ', 'portos',
        'estaleiro', 'shipyard', 'drydock', 'dry-dock', 'dry dock',
        'oficina', 'workshop',
        'parceiro', 'partner', 'fornecedor', 'supplier',
        'reparação', 'reparacao', 'repair',
        'manutenção', 'manutencao', 'maintenance',
        'overhaul', 'refit',
        'spare', 'sobressalente', 'spares',
        'oem', 'service centre', 'service center',
    ];

    /** Heuristic mapping from common terms to coverage chips. */
    private const TERM_TO_CHIP = [
        'engine'           => 'prime_movers',
        'engines'          => 'prime_movers',
        'motor'            => 'prime_movers',
        'prime mover'      => 'prime_movers',
        'overhaul'         => 'prime_movers',
        'electrical'       => 'electrical',
        'eléctrico'        => 'electrical',
        'eletrico'         => 'electrical',
        'electronic'       => 'electrical',
        'propulsor'        => 'propulsors',
        'propeller'        => 'propulsors',
        'thruster'         => 'propulsors',
        'azimuth'          => 'propulsors',
        'auxiliary'        => 'aux_systems',
        'auxiliar'         => 'aux_systems',
        'hvac'             => 'aux_systems',
        'pump'             => 'aux_systems',
        'naval weapon'     => 'naval_weapons',
        'weapon'           => 'naval_weapons',
        'fitting'          => 'ship_fittings',
        'cargo'            => 'cargo_special',
        'classification'   => 'marine_tech',
        'class society'    => 'marine_tech',
        'coating'          => 'marine_tech',
        'paint'            => 'marine_tech',
        'bunker'           => 'maritime_svc',
        'bunkering'        => 'maritime_svc',
        'agency'           => 'maritime_svc',
        'tug'              => 'maritime_svc',
        'towage'           => 'maritime_svc',
        'broker'           => 'shipbrokers',
        'terminal'         => 'port_tech',
        'port technology'  => 'port_tech',
    ];

    /**
     * Returns a markdown block to inject into the agent's context, or
     * `null` if nothing relevant was found / the message isn't a
     * partner question.
     *
     * @param string $message  raw user text
     * @param string $domain   PartnerWorkshop::DOMAIN_SPARES|DOMAIN_REPAIR
     */
    public function buildContextFor(string $message, string $domain): ?string
    {
        $lower = mb_strtolower(trim($message));
        if ($lower === '') return null;

        if (!$this->looksLikePartnerQuestion($lower)) return null;

        $matches = $this->findRelevant($lower, $domain, limit: 6);
        if ($matches->isEmpty()) return null;

        return $this->render($matches, $domain);
    }

    public function looksLikePartnerQuestion(string $lower): bool
    {
        foreach (self::PARTNER_INTENT_TOKENS as $tok) {
            if (str_contains($lower, $tok)) return true;
        }
        // Direct port-name hit also counts as intent (works for "preciso de
        // alguém em Algeciras" without any of the trigger words).
        $someKnownPort = PartnerWorkshop::query()
            ->select('port')
            ->distinct()
            ->pluck('port')
            ->some(fn(string $p) => str_contains($lower, mb_strtolower($p)));
        return $someKnownPort;
    }

    /**
     * @return Collection<int, PartnerWorkshop>
     */
    public function findRelevant(string $lower, string $domain, int $limit = 6): Collection
    {
        $q = PartnerWorkshop::query()->active()->domain($domain);

        // Port name in the message? Filter to that port.
        $hitPort = null;
        foreach (PartnerWorkshop::query()->distinct()->pluck('port') as $port) {
            if (str_contains($lower, mb_strtolower((string) $port))) {
                $hitPort = $port;
                break;
            }
        }
        if ($hitPort) $q->forPort($hitPort);

        // Service term → coverage chip filter (best-effort, OR'd).
        $chipsHit = [];
        foreach (self::TERM_TO_CHIP as $term => $chip) {
            if (str_contains($lower, $term)) $chipsHit[] = $chip;
        }
        $chipsHit = array_values(array_unique($chipsHit));
        if (!empty($chipsHit)) {
            $q->where(function ($w) use ($chipsHit) {
                foreach ($chipsHit as $chip) $w->orWhere(fn($qq) => $qq->whereChip($chip));
            });
        }

        $rows = $q->orderByRaw($this->priorityOrderSql())
                  ->orderBy('company_name')
                  ->limit($limit * 2)
                  ->get();

        // Re-rank: priority first, then chip-overlap count.
        return $rows
            ->sortBy([
                fn($r) => PartnerWorkshop::PRIORITY_ORDER[$r->priority] ?? 99,
                fn($r) => -count(array_intersect($chipsHit, array_keys(array_filter($r->coverage_chips ?? [])))),
                fn($r) => $r->company_name,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Render the partner block. Compact MD; the agent will paste it
     * directly. We keep ☎/✉ on each card so the LLM doesn't paraphrase
     * contact details (it tends to drop digits otherwise).
     */
    public function render(Collection $partners, string $domain): string
    {
        $lines = [];
        $lines[] = "<partner_workshops domain=\"{$domain}\">";
        $lines[] = sprintf(
            "%d port-workshop partner(s) from PartYard's curated network are likely relevant. "
            . "Cite verbatim — do not invent contacts.\n",
            $partners->count()
        );
        foreach ($partners as $p) {
            /** @var PartnerWorkshop $p */
            $lines[] = '- ' . $p->toAgentLine();
            if ($p->relevance) {
                $lines[] = '  · why: ' . $p->relevance;
            }
            if ($p->notes) {
                $lines[] = '  · notes: ' . $p->notes;
            }
        }
        $lines[] = "</partner_workshops>";
        return implode("\n", $lines);
    }

    /** SQL CASE expression to sort by priority enum. */
    private function priorityOrderSql(): string
    {
        $cases = [];
        foreach (PartnerWorkshop::PRIORITY_ORDER as $key => $idx) {
            $cases[] = "WHEN '{$key}' THEN {$idx}";
        }
        $cases = implode(' ', $cases);
        return "CASE priority {$cases} ELSE 99 END";
    }
}
