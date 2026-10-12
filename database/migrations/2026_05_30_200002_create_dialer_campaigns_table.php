<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_list_id')->nullable()->constrained('lead_lists')->nullOnDelete();
            $table->enum('dialer_type', ['power', 'preview'])->default('power');
            $table->string('caller_id');
            $table->string('context')->default('marcador_simulado');
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->unsignedTinyInteger('dial_block_size')->default(5);
            $table->enum('status', ['idle', 'running', 'paused', 'completed'])->default('idle');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_campaigns');
    }
};
