<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "swarm chain" execution. A chain is a multi-agent
 * pipeline that takes a single business signal (a tender, an email,
 * an equipment query) and produces one or more lead opportunities.
 *
 * The whole step-by-step trace is kept in `chain_log` JSON so an
 * admin can drill down later: "Marina said this, Marta said that,
 * Marco synthesised it as X". Critical for trust — without the
 * log nobody believes the lead is anything but vibes.
 *
 * Idempotency: signal_hash (sha1 of signal_type + signal_id) is
 * UNIQUE. Re-running the daily cron over the same set of new
 * tenders won't create duplicate runs. To force a re-run, delete
 * the row first.
 *
 * Cost: cost_usd is a running tally — every agent call updates it
 * via a transaction. Hard cap enforced in the service: a chain that
 * exceeds the per-run budget is short-circuited with status='aborted'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_swarm_runs', function (Blueprint $t) {
            $t->id();

            // Signal identity. signal_type examples: 'tender', 'email',
            // 'equipment_research'. signal_id is the source row's id
            // when applicable (tender id, message id), free-form
            // otherwise.
            $t->string('signal_type', 32)->index();
            $t->string('signal_id', 64)->nullable();
            $t->string('signal_hash', 64)->unique();
            $t->json('signal_payload')->nullable();

            // Which chain spec was used. Kept as a string so we can
            // rename the chain later without breaking historical rows.
            $t->string('chain_name', 64);

            $t->string('status', 16)->default('pending');   // pending|running|done|failed|aborted
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();

            // Step-by-step audit log. One entry per agent call:
            //   { agent: 'research', input_summary, output_summary,
            //     tokens_in, tokens_out, cost_usd, ms }
            $t->json('chain_log')->nullable();

            // Running cost tally. Decimal so we can SUM safely.
            $t->decimal('cost_usd', 8, 4)->default(0);

            // The user who fired the manual trigger (when applicable).
            // NULL for cron-driven runs.
            $t->foreignId('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_swarm_runs');
    }
};
