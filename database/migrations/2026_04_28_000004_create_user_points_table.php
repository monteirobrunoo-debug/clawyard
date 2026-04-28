<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user denormalised totals for the dashboard read path. The source
 * of truth is `reward_events`; this table is a CACHE that gets bumped
 * synchronously when an event fires. A backfill artisan command
 * re-derives this table from reward_events whenever the points formula
 * changes.
 *
 * One row per user — a row is auto-created on the first event for
 * that user (via UserPoints::firstOrCreate in the recorder service).
 *
 * Levels: derived from total_points via UserPoints::levelFor(); cached
 * here so the dashboard doesn't compute it on every page load.
 *
 * Streaks: H&P operators use the platform daily-ish; a streak counter
 * makes the engagement loop visible without being annoying. Streak
 * resets if the user skips MORE than one day (so a Saturday gap is
 * forgiven if Friday + Sunday both have activity).
 *
 * Badges: list of earned badge keys (e.g. 'first_lead_won',
 * 'agent_whisperer'). Catalogue lives in BadgeCatalog (added in C4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_points', function (Blueprint $t) {
            // Composite-PK semantics: ONE row per user.
            $t->foreignId('user_id')
                ->primary()
                ->constrained('users')
                ->cascadeOnDelete();

            // Lifetime cumulative points. Can decrease only when an
            // admin issues a correction (negative reward_event).
            $t->unsignedInteger('total_points')->default(0);

            // Cached level — UserPoints::levelFor($total). Written
            // alongside total_points to skip recompute on read.
            $t->unsignedSmallInteger('level')->default(0);

            // Streak machinery.
            $t->unsignedSmallInteger('current_streak_days')->default(0);
            $t->unsignedSmallInteger('best_streak_days')->default(0);
            $t->date('last_active_on')->nullable();

            // Earned badges. JSON list of keys. Catalogue resolved
            // via BadgeCatalog::find($key) for display metadata.
            $t->json('badges')->nullable();

            $t->timestamps();

            // Leaderboard query (top 10 by points) — index here
            // matters once we have >100 users.
            $t->index('total_points');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_points');
    }
};
