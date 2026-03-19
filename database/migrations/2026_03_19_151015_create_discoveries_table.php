<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discoveries', function (Blueprint $table) {
            $table->id();
            $table->string('source');                     // arxiv | uspto | google_patents
            $table->string('reference_id')->nullable();   // arXiv ID or Patent Number
            $table->string('title');
            $table->text('authors')->nullable();
            $table->text('summary');                      // plain language summary
            $table->string('category');                   // propulsion | maintenance | defense | seals | digital | energy | materials | quantum | other
            $table->json('activity_types');               // array: ["Peças Motores","Manutenção Preditiva",...]
            $table->string('priority')->default('watch'); // act_now | monitor | watch | awareness
            $table->integer('relevance_score')->default(5); // 1-10
            $table->text('opportunity')->nullable();      // business opportunity for PartYard/HP-Group
            $table->text('recommendation')->nullable();   // strategic recommendation
            $table->string('url')->nullable();            // link to full paper/patent
            $table->date('published_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discoveries');
    }
};
