<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent denormalised KPI snapshot. Bumped by the same recorder
 * service that writes reward_events — when an event has agent_key
 * filled, we update the matching agent_metrics row.
 *
 * Why per-agent metrics SEPARATE from user_points:
 *   • Agents don't have personalities that earn "points" — they have
 *     PERFORMANCE metrics (cost per lead, win rate, feedback ratio).
 *     Mixing them with user gamification would conflate two very
 *     different things.
 *   • Agent metrics are how we'll prune underperforming personas in
 *     A/B (drop an agent if its leads_won rate is < 5% over 100 runs).
 *   • The /agents/{key} page will show this card prominently:
 *     "Vasco generated 42 leads in 30d, 8 won, $0.18/lead avg".
 *
 * One row per agent_key — auto-created on first event via
 * AgentMetric::firstOrCreate.
 *
 * avg_score_x100: stored as integer × 100 to dodge floating-point
 * drift on incremental updates. Read as $row->avg_score_x100 / 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_metrics', function (Blueprint $t) {
            $t->string('agent_key', 32)->primary();

            // Counts.
            $t->unsignedInteger('signals_processed')->default(0);
            $t->unsignedInteger('leads_generated')->default(0);
            $t->unsignedInteger('leads_won')->default(0);

            // Cost / token usage rolled up across all swarm runs this
            // agent participated in.
            $t->decimal('total_cost_usd', 10, 4)->default(0);
            $t->unsignedBigInteger('total_tokens_in')->default(0);
            $t->unsignedBigInteger('total_tokens_out')->default(0);

            // Average lead score this agent contributed to, ×100 to
            // store as integer. Updated as a running mean — the
            // recorder reads current avg + count, computes new avg,
            // writes back. See AgentMetric::recordLeadScore().
            $t->unsignedInteger('avg_score_x100')->default(0);

            // Explicit user feedback. Drives the "trust" indicator
            // on /agents/{key}.
            $t->unsignedInteger('thumbs_up')->default(0);
            $t->unsignedInteger('thumbs_down')->default(0);

            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_metrics');
    }
};
