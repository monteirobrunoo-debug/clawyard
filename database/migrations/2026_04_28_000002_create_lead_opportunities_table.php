<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The output of a swarm run — a synthesised business opportunity
 * the team can act on. One swarm run can produce 0, 1, or more leads
 * (most produce 1; some signals produce a "no-op, not worth chasing"
 * which is captured as 0 leads).
 *
 * Lifecycle:
 *   draft      — score < 30 OR HP_HISTORY disabled. Hidden from
 *                 the daily email; visible in /leads with a tag.
 *   review     — score 30..70. Appears in admin email. Needs human
 *                 to triage before contact.
 *   confident  — score > 70. Top of the email, fast-tracked.
 *   contacted  — manager clicked "marcar como contactado" (manual)
 *   won/lost   — outcome recorded post-contact
 *   discarded  — admin decided this isn't worth pursuing
 *
 * Auditability: swarm_run_id is REQUIRED — every lead must trace
 * back to the chain that produced it. Without this we'd have a pile
 * of "Marco said so" with no provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_opportunities', function (Blueprint $t) {
            $t->id();

            $t->foreignId('swarm_run_id')
                ->constrained('agent_swarm_runs')
                ->cascadeOnDelete();

            $t->string('title', 255);
            $t->text('summary');
            $t->unsignedTinyInteger('score');     // 0..100

            // Soft hints from the chain — not strict FKs because the
            // synthesiser may infer customer/equipment from text and
            // we don't want a NULL FK to make the lead disappear.
            $t->string('customer_hint', 255)->nullable();
            $t->string('equipment_hint', 255)->nullable();

            // Original signal that triggered this. Mirrors
            // swarm_runs.signal_type/signal_id so a SQL JOIN isn't
            // mandatory for the listing page.
            $t->string('source_signal_type', 32)->index();
            $t->string('source_signal_id', 64)->nullable();

            $t->string('status', 16)->default('draft')->index();

            // When status moves to 'contacted', who from the team
            // is following up. NULL until assigned.
            $t->foreignId('assigned_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->text('notes')->nullable();        // free-form follow-up notes
            $t->timestamp('contacted_at')->nullable();
            $t->timestamps();

            // Index for the daily email query (status, score desc).
            $t->index(['status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_opportunities');
    }
};
