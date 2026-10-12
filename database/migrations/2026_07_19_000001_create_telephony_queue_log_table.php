<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_queue_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('pbx_id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('asterisk_queue_log_id');
            $table->timestamp('event_time')->nullable();
            $table->string('linkedid', 80);
            $table->string('queuename', 120)->nullable();
            $table->string('agent', 120)->nullable();
            $table->string('event', 80);
            $table->string('data1')->nullable();
            $table->string('data2')->nullable();
            $table->string('data3')->nullable();
            $table->string('data4')->nullable();
            $table->string('data5')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['pbx_id', 'asterisk_queue_log_id'], 'tql_pbx_asterisk_id_unique');
            $table->index(['pbx_id', 'linkedid'], 'tql_pbx_linkedid_idx');
            $table->index(['tenant_id', 'linkedid'], 'tql_tenant_linkedid_idx');
            $table->index('event', 'tql_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_queue_log');
    }
};
