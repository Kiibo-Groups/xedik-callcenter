<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_dialer_offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('dialer_campaign_id')
                ->constrained('dialer_campaigns')
                ->cascadeOnDelete();

            $table->foreignId('lead_list_id')
                ->constrained('lead_lists')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('lead_list_row_id');
            $table->string('phone', 40);
            $table->string('reservation_token', 80);
            $table->string('status', 20)->default('pending');
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('call_uuid', 36)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'pdo_tenant_user_unique');
            $table->unique('reservation_token', 'pdo_token_unique');
            $table->unique(['lead_list_id', 'lead_list_row_id'], 'pdo_lead_row_unique');
            $table->index('expires_at', 'pdo_expires_idx');
            $table->index('dialer_campaign_id', 'pdo_campaign_idx');
            $table->index('call_uuid', 'pdo_call_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_dialer_offers');
    }
};
