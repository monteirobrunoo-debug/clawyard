<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-editable app settings.
 *
 * Distinct from .env: .env is for SERVER-managed values (API keys,
 * DB credentials) that an admin shouldn't be touching from a web UI.
 * app_settings holds OPERATOR-managed knobs — feature flags,
 * notification thresholds, default values — that an admin can flip
 * on the admin panel without SSHing into the server.
 *
 * Schema:
 *   key          — slug (unique), e.g. "feature.ticker.enabled"
 *   value        — string (small JSON or scalar)
 *   category     — bucket label for the UI ("feature_flags", "notifications", …)
 *   description  — human note shown next to the toggle
 *   updated_by_user_id — audit
 *   timestamps
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->text('value')->nullable();
            $t->string('category', 32)->default('general')->index();
            $t->string('description', 255)->nullable();
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
