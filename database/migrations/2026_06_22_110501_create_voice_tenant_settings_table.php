<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_tenant_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->unique()
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->boolean('voice_enabled')->default(false);

            $table->string('dialplan_context', 120)->nullable();
            $table->string('outbound_context', 120)->nullable();
            $table->string('default_caller_id', 40)->nullable();

            $table->unsignedInteger('session_ttl_seconds')->default(28800);
            $table->unsignedTinyInteger('max_sessions_per_agent')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_tenant_settings');
    }
};