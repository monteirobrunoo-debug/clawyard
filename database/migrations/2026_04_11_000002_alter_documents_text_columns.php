<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('title')->change();
            $table->text('file_path')->nullable()->change();
            $table->text('source')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('title')->change();
            $table->string('file_path')->nullable()->change();
            $table->string('source')->nullable()->change();
        });
    }
};
