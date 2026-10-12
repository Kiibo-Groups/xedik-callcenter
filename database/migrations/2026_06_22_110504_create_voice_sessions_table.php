<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('voice_extension_id')
                ->constrained('voice_extensions')
                ->cascadeOnDelete();

            $table->string('browser_tab_id', 120);
            $table->uuid('session_uuid')->unique();

            $table->string('status', 32)->default('pending');

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('failure_reason', 255)->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(
                ['tenant_id', 'user_id', 'status'],
                'voice_sessions_tenant_user_status_index'
            );

            $table->index(
                ['tenant_id', 'browser_tab_id'],
                'voice_sessions_tenant_tab_index'
            );

            $table->index(
                ['status', 'last_seen_at'],
                'voice_sessions_status_last_seen_index'
            );

            $table->index(
                ['status', 'expires_at'],
                'voice_sessions_status_expires_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};