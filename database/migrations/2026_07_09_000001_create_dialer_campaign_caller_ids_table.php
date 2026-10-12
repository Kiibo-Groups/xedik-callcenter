<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_campaign_caller_ids', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('dialer_campaign_id')
                ->constrained('dialer_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('voice_caller_id_id')
                ->constrained('voice_caller_ids')
                ->cascadeOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();

            $table->timestamps();

            $table->unique(
                ['dialer_campaign_id', 'voice_caller_id_id'],
                'dcid_campaign_callerid_unique'
            );

            $table->index(
                ['dialer_campaign_id', 'is_active', 'last_used_at'],
                'dcid_campaign_active_lru_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_campaign_caller_ids');
    }
};
