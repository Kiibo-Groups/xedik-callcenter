<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_caller_ids', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('voice_gateway_id')
                ->nullable()
                ->constrained('voice_gateways')
                ->nullOnDelete();

            $table->string('name', 120)->nullable();
            $table->string('number', 40);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(true);

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'number'],
                'voice_caller_ids_tenant_number_unique'
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'voice_caller_ids_tenant_active_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_caller_ids');
    }
};
