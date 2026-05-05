<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly digest email system — sends activity summary every Friday.
 *
 *   • users.weekly_digest_enabled — opt-out flag (default true)
 *   • user_weekly_digests — log of each digest sent (one row per
 *     user × week_start_date) so a re-run on Friday is idempotent
 *     and we can show "your last digest" history later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('weekly_digest_enabled')->default(true)->after('last_otp_at');
        });

        Schema::create('user_weekly_digests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('week_start');                    // Monday of the digest week
            $t->json('stats')->nullable();              // captured snapshot for audit
            $t->timestamp('sent_at')->nullable();
            $t->string('error', 255)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_weekly_digests');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('weekly_digest_enabled');
        });
    }
};
