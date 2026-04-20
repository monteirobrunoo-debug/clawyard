<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group multiple agent shares under a single client portal.
 *
 * When the owner shares N agents with the same client in one go, all N
 * rows receive the same `portal_token`. The client then visits a single
 * /p/{portal_token} landing page listing every agent in the bundle.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_shares', 'portal_token')) {
                $table->string('portal_token', 40)->nullable()->after('token');
                $table->index('portal_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            if (Schema::hasColumn('agent_shares', 'portal_token')) {
                $table->dropIndex(['portal_token']);
                $table->dropColumn('portal_token');
            }
        });
    }
};
