<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification preferences per user (pedido Bruno 2026-05-28):
 *   "os emails da nspa e dos concursos estão a ser enviados para as
 *    pessoas todas — não tens de enviar apenas os que estão atribuídos
 *    ao user"
 *
 * Adiciona 2 colunas boolean para o user controlar:
 *   - daily_digest_enabled: recebe TenderDailyDigest (default true, conserva
 *     o comportamento actual)
 *   - deadline_alerts_enabled: recebe TenderDeadlineAlert (default true)
 *
 * weekly_digest_enabled já existe da migration 2026_05_05_000003. Mantida.
 *
 * Default true para que ninguém deixe abruptly de receber digests no
 * deploy — admin pode mudar /admin/users ou cada user em /profile.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('daily_digest_enabled')->default(true)->after('weekly_digest_enabled');
            $t->boolean('deadline_alerts_enabled')->default(true)->after('daily_digest_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['daily_digest_enabled', 'deadline_alerts_enabled']);
        });
    }
};