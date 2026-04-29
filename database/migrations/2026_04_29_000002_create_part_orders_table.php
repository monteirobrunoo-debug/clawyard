<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The output of an agent's autonomous shopping loop. ONE row per
 * "purchase decision" — which is the single artefact that captures:
 *
 *   1. Which agent decided what to buy (agent_key)
 *   2. The committee deliberation (committee_log JSON)
 *   3. What they searched and chose (search_query, source_url, image)
 *   4. The CAD design that came out the other end (design_scad, stl_path)
 *
 * Lifecycle:
 *   committee  → multi-agent deliberation in progress (D3)
 *   searching  → buyer agent doing web search (D4)
 *   purchased  → agent picked one, balance debited (D4)
 *   designing  → LLM generating OpenSCAD code (D5)
 *   stl_ready  → .stl file on disk, ready to download (D5)
 *   cnc_queued → operator pushed it to a CNC machine (future)
 *   completed  → physical part delivered (future)
 *   cancelled  → committee aborted / search empty / design failed
 *
 * stl_path is the relative key under storage/app/parts/ — the actual
 * file lives outside DB so the row stays small.
 *
 * design_scad: the OpenSCAD source the LLM wrote. Kept even after
 * conversion to STL so users can edit/regenerate without re-prompting.
 *
 * committee_log: array of agent contributions during deliberation.
 * Format: [{ agent_key, role: 'buyer'|'helper', text, at }, …]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_orders', function (Blueprint $t) {
            $t->id();

            // The buyer — wallet is debited from this agent_key.
            $t->string('agent_key', 32)->index();

            // What the agent decided to get.
            $t->string('name', 255);
            $t->text('description')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->string('source_image_url', 500)->nullable();
            $t->decimal('cost_usd', 10, 4)->default(0);

            // Lifecycle.
            $t->string('status', 20)->default('committee')->index();

            // Web search context — kept so we can audit "why did the
            // agent pick this one when 4 others were cheaper?".
            $t->string('search_query', 255)->nullable();
            $t->json('search_candidates')->nullable();

            // Multi-agent deliberation log.
            $t->json('committee_log')->nullable();

            // CAD output.
            $t->text('design_scad')->nullable();
            $t->string('stl_path', 500)->nullable();
            $t->timestamp('designed_at')->nullable();

            // Free-form notes for any phase (e.g. "search returned 0 hits").
            $t->text('notes')->nullable();

            $t->timestamps();

            // Hot index: per-agent gallery on /agents/{key}.
            $t->index(['agent_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_orders');
    }
};
