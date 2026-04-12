<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('agent_key', 32);          // who published (e.g. 'vessel', 'aria')
            $table->string('agent_name', 64);          // display name (e.g. 'Capitão Vasco')
            $table->string('context_key', 64);         // topic key (e.g. 'vessel_research')
            $table->text('summary');                   // max ~600 chars of findings
            $table->json('tags')->nullable();          // relevance tags for filtering
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['agent_key', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_contexts');
    }
};
