<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full audit trail for every interaction with a share link.
 * Populated by AgentShareAccessService so the owner can see who opened
 * what and from where, and so we can detect new-device conditions.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agent_share_access_logs')) return;

        Schema::create('agent_share_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_share_id')->constrained()->cascadeOnDelete();
            $table->string('email', 150)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('country', 3)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('event', 40);        // open, otp_requested, otp_verified, otp_failed, blocked_device, stream, revoked
            $table->string('status', 20);       // allowed | denied
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['agent_share_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_share_access_logs');
    }
};
