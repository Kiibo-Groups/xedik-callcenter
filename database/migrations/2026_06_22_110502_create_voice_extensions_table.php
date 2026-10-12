<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_extensions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('extension', 32);
            $table->string('display_name', 120)->nullable();

            $table->string('caller_id_number', 40)->nullable();
            $table->string('caller_id_name', 120)->nullable();

            $table->string('dialplan_context', 120)->nullable();
            $table->string('outbound_context', 120)->nullable();

            $table->string('sip_username', 120)->nullable();
            $table->string('pjsip_endpoint', 120)->nullable();
            $table->string('pjsip_auth', 120)->nullable();
            $table->string('pjsip_aor', 120)->nullable();

            $table->boolean('allow_outbound')->default(true);
            $table->boolean('allow_internal')->default(true);
            $table->boolean('allow_transfer')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamp('pjsip_provisioned_at')->nullable();
            $table->timestamp('sip_password_rotated_at')->nullable();
            $table->timestamp('sip_invalidated_at')->nullable();

            $table->string('sip_provisioning_status', 32)
                ->default('pending');

            $table->text('sip_provisioning_error')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'extension'],
                'voice_extensions_tenant_extension_unique'
            );

            $table->unique(
                ['tenant_id', 'user_id'],
                'voice_extensions_tenant_user_unique'
            );

            $table->unique('sip_username');
            $table->unique('pjsip_endpoint');
            $table->unique('pjsip_auth');
            $table->unique('pjsip_aor');

            $table->index(
                ['tenant_id', 'is_active'],
                'voice_extensions_tenant_active_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_extensions');
    }
};