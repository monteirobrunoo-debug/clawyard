<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per agent_key. The agent's "wallet" — balance is the spendable
 * USD they've earned but not spent on robot parts yet. Lifetime counters
 * are auditing surfaces (didn't compute the wallet from agent_metrics
 * directly because the credit formula will likely evolve, and the
 * lifetime totals here are an immutable trail of credit/debit events).
 *
 * Crediting cadence: a daily cron walks agent_metrics, computes the
 * delta since `last_credit_at`, and adds to balance_usd + lifetime_earned.
 * `last_credit_basis` snapshots the metric values AT CREDIT TIME so the
 * next run can compute the delta even if the formula changes.
 *
 * Debiting: when an agent "buys" a robot part (D3-D4), a part_orders
 * row is created and balance is debited inside the same transaction.
 *
 * Why a dedicated table vs a derived column on agent_metrics:
 *   1. Idempotent crediting is easier with a snapshot column
 *   2. Multi-currency in the future ($, €) can fit here cleanly
 *   3. Wallet semantics (debits, refunds) don't pollute the metrics
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_wallets', function (Blueprint $t) {
            $t->string('agent_key', 32)->primary();

            // Spendable balance + lifetime audit surfaces.
            $t->decimal('balance_usd',         10, 4)->default(0);
            $t->decimal('lifetime_earned_usd', 10, 4)->default(0);
            $t->decimal('lifetime_spent_usd',  10, 4)->default(0);

            // Idempotent credit cron — last run timestamp and the metric
            // values at that point. Next run reads agent_metrics, diffs
            // against this snapshot, credits the delta.
            $t->timestamp('last_credit_at')->nullable();
            $t->json('last_credit_basis')->nullable();

            $t->timestamps();

            // Leaderboard query (top-spender / top-earner).
            $t->index('balance_usd');
            $t->index('lifetime_earned_usd');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_wallets');
    }
};
