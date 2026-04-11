<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();           // URL token: /a/{token}
            $table->string('agent_key', 50);                 // e.g. 'aria', 'capitao', 'email'
            $table->string('client_name');                   // Display name for client
            $table->string('client_email')->nullable();      // Optional client email
            $table->string('password_hash')->nullable();     // Optional password protection
            $table->string('custom_title')->nullable();      // Override agent title shown to client
            $table->text('welcome_message')->nullable();     // Custom welcome message
            $table->boolean('show_branding')->default(true); // Show PartYard branding
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();     // null = never expires
            $table->unsignedBigInteger('created_by');        // user id
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_shares');
    }
};
