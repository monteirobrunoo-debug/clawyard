<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push subscriptions — guarda 1 row por device subscrito por user.
 *
 * Cada device gera o seu próprio endpoint via PushManager.subscribe()
 * no browser. O endpoint + chaves (p256dh, auth) são enviados ao
 * server e armazenados. Quando queremos enviar uma push (ex.: deadline
 * em 24h), iteramos os subscriptions do user e disparamos via VAPID
 * → o vendor URL (FCM/APNS/Mozilla) entrega ao device.
 *
 * 2026-05-20.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint');                 // browser-vendor URL
            $t->string('p256dh', 200);            // pub key user-agent (base64url)
            $t->string('auth', 100);              // shared secret (base64url)
            $t->string('content_encoding', 20)->default('aes128gcm');
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'endpoint']);  // 1 device por user, idempotent
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
