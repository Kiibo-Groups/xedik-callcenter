<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_automation_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp | instagram | facebook | chat | email...
            $table->boolean('enabled')->default(false);
            $table->string('webhook_url')->nullable();
            $table->json('events')->nullable(); // e.g. ["inbound_message"]
            $table->string('auth_type', 20)->default('none'); // none | api_key
            $table->text('api_key')->nullable(); // cast encrypted in model
            $table->unsignedInteger('timeout_ms')->default(5000);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_automation_channels');
    }
};
