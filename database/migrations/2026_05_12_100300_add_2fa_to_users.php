<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * #10 — TOTP 2FA for admin accounts.
     *
     * Stores the base32 secret + recovery codes. Both encrypted via
     * Laravel's `encrypted` cast (APP_KEY). Backed by the standard
     * pragmarx/google2fa flow (Google Authenticator / Authy / 1Password).
     *
     * Activation flow:
     *   1. Admin visits /profile/2fa → controller generates secret + QR
     *   2. User scans QR in authenticator app
     *   3. Confirms by submitting current 6-digit code → secret persisted
     *   4. From next login, /login/2fa challenge appears before session lands
     *
     * Bypass: `two_factor_secret IS NULL` means 2FA off — keeps existing
     * non-admin users untouched. Per policy, admins MUST enable within 7
     * days of first login; enforcement check lives in middleware.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
