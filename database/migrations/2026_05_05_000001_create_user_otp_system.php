<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP-based OTP verification system for clawyard users.
 *
 *   • users.last_verified_ip / last_otp_at — track which IP a user last
 *     proved control of via email-delivered OTP.
 *   • user_otp_codes — short-lived (10min, 5 attempt cap) hashed codes
 *     pending verification, one row per challenge.
 *
 * Mirrors the AgentShareOtp pattern (same hashing, same expiry policy)
 * but scoped to internal users instead of external client portal access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = never verified or just logged in/logged out
            $table->ipAddress('last_verified_ip')->nullable()->after('allowed_nav');
            $table->timestamp('last_otp_at')->nullable()->after('last_verified_ip');
        });

        Schema::create('user_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 100);          // bcrypt of the 6-digit code
            $table->ipAddress('ip');                   // IP that requested the code
            $table->string('user_agent', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_otp_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_verified_ip', 'last_otp_at']);
        });
    }
};
