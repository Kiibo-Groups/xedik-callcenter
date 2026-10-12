<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_dial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dialer_campaign_id')->constrained('dialer_campaigns')->cascadeOnDelete();
            $table->string('lead_list_table');
            $table->unsignedBigInteger('dialer_lead_id');
            $table->string('phone', 20);
            $table->enum('status', ['pending', 'dialing'])->default('pending');
            $table->timestamp('queued_at')->useCurrent();

            $table->index(['dialer_campaign_id', 'status']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_dial');
    }
};
