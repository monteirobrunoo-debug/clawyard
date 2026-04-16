<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            // false = SAP data blocked for external users (default safe)
            $table->boolean('allow_sap_access')->default(false)->after('show_branding');
        });
    }

    public function down(): void
    {
        Schema::table('agent_shares', function (Blueprint $table) {
            $table->dropColumn('allow_sap_access');
        });
    }
};
