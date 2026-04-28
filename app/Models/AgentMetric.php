<?php

namespace App\Models;

use App\Services\AgentCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-agent denormalised KPI snapshot. ONE row per agent_key — the
 * row is created lazily on the first event for that agent (via
 * AgentMetric::firstOrCreate in the recorder).
 *
 * Used by:
 *   • /agents/{key} performance card — "leads generated, win rate,
 *     avg cost per lead"
 *   • A/B comparison admin tool — drop underperforming personas
 *   • Daily digest "your agents" panel
 *
 * NOT used as a source of truth for billing — that comes from
 * agent_swarm_runs.cost_usd (per-run audit trail). This table is the
 * AGGREGATE for the UI; a backfill artisan command can rebuild it
 * from reward_events when the formula changes.
 */
class AgentMetric extends Model
{
    protected $table = 'agent_metrics';
    protected $primaryKey = 'agent_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'agent_key',
        'signals_processed',
        'leads_generated',
        'leads_won',
        'total_cost_usd',
        'total_tokens_in',
        'total_tokens_out',
        'avg_score_x100',
        'thumbs_up',
        'thumbs_down',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at'        => 'datetime',
            'total_cost_usd'     => 'decimal:4',
            'total_tokens_in'    => 'integer',
            'total_tokens_out'   => 'integer',
            'signals_processed' => 'integer',
            'leads_generated'   => 'integer',
            'leads_won'         => 'integer',
            'avg_score_x100'    => 'integer',
            'thumbs_up'         => 'integer',
            'thumbs_down'       => 'integer',
        ];
    }

    /** Read the average score back as a float. */
    public function avgScore(): float
    {
        return $this->avg_score_x100 / 100;
    }

    /**
     * Win rate as a percentage 0..100. NULL when no leads have been
     * generated yet (avoids 0/0 = NaN in the UI).
     *
     * Truthy-check (not `=== 0`) deliberately — a freshly-created
     * AgentMetric row may have NULL counters in memory until the
     * first `fresh()` reload (DB defaults don't propagate to the
     * in-memory instance).
     */
    public function winRate(): ?float
    {
        $generated = (int) $this->leads_generated;
        if ($generated <= 0) return null;
        return round(((int) $this->leads_won / $generated) * 100, 1);
    }

    /**
     * Cost per generated lead. NULL when none generated yet.
     */
    public function costPerLead(): ?float
    {
        $generated = (int) $this->leads_generated;
        if ($generated <= 0) return null;
        return round((float) $this->total_cost_usd / $generated, 4);
    }

    /**
     * Trust ratio derived from explicit user feedback (👍 / 👎).
     * Returns a 0..100 percentage of positive feedback, or NULL
     * when no feedback yet (avoids implying agents start at 0%).
     */
    public function trustPct(): ?float
    {
        $up   = (int) $this->thumbs_up;
        $down = (int) $this->thumbs_down;
        $total = $up + $down;
        if ($total <= 0) return null;
        return round(($up / $total) * 100, 1);
    }

    /**
     * Pull display metadata (name, emoji, role, color) from the
     * single source of truth so the metrics card on /agents/{key}
     * stays consistent with the rest of the UI.
     */
    public function meta(): ?array
    {
        return AgentCatalog::find($this->agent_key);
    }

    /**
     * Update the running mean score given a new lead score. Stored
     * as integer × 100 to avoid floating-point drift on incremental
     * updates. Caller is responsible for incrementing leads_generated
     * BEFORE calling this so the new count reflects the new lead.
     *
     * Formula: new_avg = old_avg + (new_value - old_avg) / count
     * (Welford-style running mean — numerically stable).
     */
    public function applyNewLeadScore(int $newScore): void
    {
        $count = max(1, $this->leads_generated);
        $oldAvg = $this->avg_score_x100;                  // already × 100
        $delta  = ($newScore * 100) - $oldAvg;
        $this->avg_score_x100 = (int) round($oldAvg + ($delta / $count));
    }
}
