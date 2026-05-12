<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Short Haiku-generated label (3-6 words). Plain text — not
            // sensitive, surfaced in the conversation list UI. Indexed so
            // /admin/conversations search-by-title is fast.
            $table->string('title', 120)->nullable()->after('agent');
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['title']);
            $table->dropColumn('title');
        });
    }
};
