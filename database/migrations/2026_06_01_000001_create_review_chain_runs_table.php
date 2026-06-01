<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_chain_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|running|done|failed
            // Array of {agent_key, name, emoji, role, approved, verdict, notes, flags[], confidence, cost_usd, ms}
            $table->jsonb('steps')->nullable();
            $table->boolean('overall_approved')->nullable();
            // Index of the step that halted the chain (0-based), null if all passed
            $table->unsignedTinyInteger('stopped_at_step')->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_chain_runs');
    }
};
