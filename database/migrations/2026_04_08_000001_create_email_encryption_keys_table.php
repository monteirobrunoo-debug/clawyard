<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_encryption_keys', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique()->comment('Email address the key belongs to');
            $table->text('public_key')->comment('Base64-encoded Kyber-1024 public key (1568 bytes)');
            $table->string('key_fingerprint', 64)->comment('SHA-256 hex fingerprint of the public key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_encryption_keys');
    }
};
