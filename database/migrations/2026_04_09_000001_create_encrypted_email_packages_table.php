<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encrypted_email_packages', function (Blueprint $table) {
            $table->id();
            $table->string('token', 12)->unique()->comment('Short public token used in /decrypt/{token} URL');
            $table->longText('package')->comment('JSON-encoded encrypted package (may contain base64 attachments)');
            $table->timestamp('expires_at')->nullable()->comment('Auto-delete after 30 days');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encrypted_email_packages');
    }
};
