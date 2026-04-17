<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time passwords issued for /a/{token} sessions.
 *   - code_hash: sha256 of the 6-digit code (never store the plaintext code)
 *   - attempts: throttle brute force (we allow 5 attempts per code)
 *   - used_at: prevent replay
 *   - expires_at: 10 minute window
 *   - session_id: binds the OTP to the browser session that requested it
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agent_share_otps')) return;

        Schema::create('agent_share_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_share_id')->constrained()->cascadeOnDelete();
            $table->string('email', 150);
            $table->string('session_id', 64);
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['agent_share_id', 'session_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_share_otps');
    }
};
