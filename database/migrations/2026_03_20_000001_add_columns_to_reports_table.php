<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            if (!Schema::hasColumn('reports', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('reports', 'title')) {
                $table->string('title')->after('user_id');
            }
            if (!Schema::hasColumn('reports', 'type')) {
                $table->string('type')->default('general')->after('title');
            }
            if (!Schema::hasColumn('reports', 'content')) {
                $table->longText('content')->after('type');
            }
            if (!Schema::hasColumn('reports', 'summary')) {
                $table->text('summary')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'title', 'type', 'content', 'summary']);
        });
    }
};
