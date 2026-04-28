<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log of every reward-earning event in the system. ONE row
 * per action — adding a tender, qualifying a lead, agent chat session,
 * agent thumbs-up, etc. Two derivative tables (`user_points`,
 * `agent_metrics`) store running totals so the dashboard read path
 * doesn't COUNT/SUM millions of rows on every page hit.
 *
 * Why a separate event log instead of just bumping totals:
 *   • Auditability — operator can see exactly WHY a user has 4,212
 *     points (which leads, which agents, which dates).
 *   • Replay — if we change the points formula, a single backfill
 *     job re-derives user_points from the events.
 *   • Polymorphism — the SAME event log handles user-rooted events
 *     ("Marco qualified lead 42") and agent-rooted events ("Vasco's
 *     synth produced lead 17") via subject_type/subject_id.
 *
 * Why nullable user_id + agent_key (both):
 *   • user_id NULL: system-generated events (cron firing a swarm run,
 *     synth producing a lead) — no human earned points but we still
 *     want the agent metric.
 *   • agent_key NULL: pure user actions (login, tender import) that
 *     don't involve a specific agent.
 *   • At least ONE of (user_id, agent_key) is non-null in practice;
 *     not enforced via CHECK because Laravel's MySQL drivers don't
 *     uniformly support it across versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_events', function (Blueprint $t) {
            $t->id();

            // Who earned this. NULL when the event is purely about an
            // agent's run (cron-triggered, no human in the loop).
            $t->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Which agent the event relates to. NULL for non-agent
            // events (tender import, daily login).
            $t->string('agent_key', 32)->nullable()->index();

            // Event taxonomy — see RewardEvent::TYPE_* constants.
            // String not enum because adding a new event type
            // shouldn't require a migration.
            $t->string('event_type', 48)->index();

            // Points awarded for THIS event. Can be 0 (stat-only,
            // e.g. agent_chat exceeding the daily cap) or negative
            // (correction/penalty — admin reverses a bad lead award).
            $t->integer('points')->default(0);

            // Polymorphic link to the thing the event is ABOUT.
            // subject_type: 'lead', 'tender', 'swarm_run', 'message', …
            // subject_id  : id within that table.
            // Both nullable for events that are intrinsically about
            // the user/agent only (login, daily streak).
            $t->string('subject_type', 32)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();

            // Free-form context the points formula or replay job
            // might need: { 'lead_score': 82, 'tender_id': 17, … }
            $t->json('metadata')->nullable();

            $t->timestamps();

            // Hot indexes:
            //   • dashboard "your last 30 days" → user + created_at
            //   • agent metric rollup → agent_key + event_type
            //   • event-type aggregations (e.g. "how many leads_won
            //     this quarter?") → event_type + created_at
            $t->index(['user_id',   'created_at']);
            $t->index(['agent_key', 'event_type']);
            $t->index(['event_type', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_events');
    }
};
