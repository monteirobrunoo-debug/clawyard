<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-recipient shares.
 *
 * `client_email` stays as the primary / canonical address — we still show it
 * on admin cards and use it as the "From/For" line in notifications.
 * `additional_emails` is a JSON array of extra authorised recipients. The OTP
 * service validates an incoming email against the *union* of these two fields,
 * so any authorised recipient can claim the share using their own address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            // Nullable JSON so existing rows (single-recipient) stay valid
            // without a data migration. Empty / null = single-recipient share.
            $table->json('additional_emails')->nullable()->after('client_email');
        });
    }

    public function down(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            $table->dropColumn('additional_emails');
        });
    }
};
