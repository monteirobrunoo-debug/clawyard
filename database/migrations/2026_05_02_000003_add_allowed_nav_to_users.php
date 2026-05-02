<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user navigation visibility whitelist. Same pattern as
 * allowed_agents — null means "use role defaults", [] means
 * "blocked from all nav", [...] means explicit whitelist.
 *
 * Controlled via the /admin/nav-access matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('allowed_nav')->nullable()->after('allowed_agents');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allowed_nav');
        });
    }
};
