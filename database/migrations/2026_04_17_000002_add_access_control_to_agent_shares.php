<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardens /a/{token} against link-forwarding:
 *   - require_otp: force email-based OTP challenge on every new session
 *   - lock_to_device: pin the session to the first browser that opens it
 *   - notify_on_access / notify_email / notify_whatsapp: ping the owner
 *     every time someone opens the link (with IP + UA)
 *   - revoked_at: kill switch that short-circuits isValid()
 *
 * Existing shares default to the stricter configuration so forwarding a
 * previously-issued token no longer grants access.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            // Idempotent: skip columns that already exist so re-runs after
            // partial applies (e.g. manual SQL during hot-fix) don't explode.
            if (!Schema::hasColumn('agent_shares', 'require_otp')) {
                $table->boolean('require_otp')->default(true)->after('password_hash');
            }
            if (!Schema::hasColumn('agent_shares', 'lock_to_device')) {
                $table->boolean('lock_to_device')->default(true)->after('require_otp');
            }
            if (!Schema::hasColumn('agent_shares', 'notify_on_access')) {
                $table->boolean('notify_on_access')->default(true)->after('lock_to_device');
            }
            if (!Schema::hasColumn('agent_shares', 'notify_email')) {
                $table->string('notify_email', 150)->nullable()->after('notify_on_access');
            }
            if (!Schema::hasColumn('agent_shares', 'notify_whatsapp')) {
                $table->string('notify_whatsapp', 30)->nullable()->after('notify_email');
            }
            if (!Schema::hasColumn('agent_shares', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('last_used_at');
            }
            if (!Schema::hasColumn('agent_shares', 'revoked_reason')) {
                $table->string('revoked_reason', 255)->nullable()->after('revoked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            $table->dropColumn([
                'require_otp',
                'lock_to_device',
                'notify_on_access',
                'notify_email',
                'notify_whatsapp',
                'revoked_at',
                'revoked_reason',
            ]);
        });
    }
};
