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
            $table->boolean('require_otp')->default(true)->after('password_hash');
            $table->boolean('lock_to_device')->default(true)->after('require_otp');
            $table->boolean('notify_on_access')->default(true)->after('lock_to_device');
            $table->string('notify_email', 150)->nullable()->after('notify_on_access');
            $table->string('notify_whatsapp', 30)->nullable()->after('notify_email');
            $table->timestamp('revoked_at')->nullable()->after('last_used_at');
            $table->string('revoked_reason', 255)->nullable()->after('revoked_at');
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
