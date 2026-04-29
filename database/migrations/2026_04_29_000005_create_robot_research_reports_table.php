<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — Research Council reports.
 *
 * Periodically (or on-demand), 4 agents convene to research a robot
 * improvement topic. Each searches the web from their persona angle
 * (vessel for marine durability, finance for cost, engineer for
 * mechanics, etc.) and contributes findings. The lead agent then
 * synthesises a final summary + actionable proposals.
 *
 * Stored as ONE row per research session for the auditable timeline
 * on /robot/research — operators can scroll back through every
 * council and see what the agents actually decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_research_reports', function (Blueprint $t) {
            $t->id();

            // What the council is researching. Free-text so we can
            // add new topics without a migration.
            $t->string('topic', 255);

            // committee_running, complete, cancelled. Most reports
            // complete; cancelled means the LLM dispatches all failed.
            $t->string('status', 20)->default('running')->index();

            // The agent that convened the council. Usually picked
            // because the topic aligns with their domain.
            $t->string('leading_agent', 32);

            // All agents that contributed (lead + 3 participants).
            $t->json('participants');

            // Per-agent findings — each entry:
            //   { agent_key, persona_angle, search_query, search_text_snippet,
            //     findings_md, at }
            $t->json('findings')->nullable();

            // Lead agent's synthesis — markdown. The 'voice of the
            // council' — what they collectively concluded.
            $t->text('final_summary')->nullable();

            // Concrete actionable items the council recommends:
            //   [{ kind: 'swap'|'add_slot'|'budget_bump'|'persona_change'|'note',
            //      target: 'slot_x' | 'agent_y' | null,
            //      suggestion: '<sentence>' }, …]
            $t->json('proposals')->nullable();

            // LLM token cost of the whole council session — visible on
            // the timeline so operators see what councils 'cost' to run.
            $t->decimal('total_cost_usd', 10, 4)->default(0);

            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_research_reports');
    }
};
