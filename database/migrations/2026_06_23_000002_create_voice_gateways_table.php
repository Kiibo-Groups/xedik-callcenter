<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_gateways', function (Blueprint $table) {
            $table->id();
            // null = global gateway available to all tenants
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name', 120);
            // The PJSIP endpoint ID configured in Asterisk (e.g. "signalwave_mx")
            $table->string('asterisk_endpoint_id', 80)->unique();
            // Optional technical prefix prepended to destination (invisible to agents)
            $table->string('hidden_prefix', 20)->nullable();
            $table->string('country_code', 5)->nullable();
            $table->unsignedTinyInteger('national_number_length')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['is_active', 'is_default'], 'vg_active_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_gateways');
    }
};
