<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for admin actions on User rows.
 *
 * Why a dedicated table (vs Log::info to laravel.log):
 *   - laravel.log rotates; auditors lose history.
 *   - laravel.log is line-oriented; "who promoted whom in the last
 *     30 days" needs grep + parsing instead of a SQL query.
 *   - Multi-tenant compliance (SOC2 / ISO 27001) typically requires
 *     a queryable, retained record of role changes.
 *
 * Schema is intentionally minimal + extensible:
 *   - `event_type` is a free string keyed by the controller (e.g.
 *     "role_change", "deactivate", "reactivate", "delete"). New
 *     events don't need a new column.
 *   - `payload` JSON carries event-specific data (from/to role,
 *     reason text, etc.). Read-side code can still index into it
 *     without a migration.
 *   - Timestamps are `created_at` only — events are immutable.
 *
 * Retention is deliberately NOT enforced here; the user can prune
 * with a one-line truncate or scheduled DELETE if compliance asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_admin_events', function (Blueprint $t) {
            $t->id();

            // The User the action was performed ON.
            $t->foreignId('target_user_id')
              ->constrained('users')
              ->cascadeOnDelete();

            // The admin who fired the action. Nullable because:
            //   - artisan / system jobs may write events with no actor;
            //   - keeping history when the actor's account is later
            //     deleted (set null preserves the audit trail).
            $t->foreignId('actor_user_id')
              ->nullable()
              ->constrained('users')
              ->nullOnDelete();

            $t->string('event_type', 64);
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['target_user_id', 'created_at']);
            $t->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_admin_events');
    }
};
