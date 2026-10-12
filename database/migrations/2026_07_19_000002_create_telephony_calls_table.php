<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telephony_calls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('pbx_id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('linkedid', 80);
            $table->string('did', 80)->nullable();
            $table->string('queue_name', 120)->nullable();
            $table->string('caller_number', 80)->nullable();
            $table->string('agent', 120)->nullable();
            $table->timestamp('entered_queue_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('exit_reason', 40)->nullable();
            $table->unsignedInteger('wait_seconds')->nullable();
            $table->unsignedInteger('talk_seconds')->nullable();
            $table->unsignedInteger('call_duration_seconds')->nullable();
            $table->timestamps();

            $table->unique(['pbx_id', 'linkedid'], 'tc_pbx_linkedid_unique');
            $table->index(['tenant_id', 'entered_queue_at'], 'tc_tenant_entered_idx');
            $table->index(['tenant_id', 'queue_name'], 'tc_tenant_queue_idx');
            $table->index(['tenant_id', 'agent'], 'tc_tenant_agent_idx');
            $table->index(['tenant_id', 'exit_reason'], 'tc_tenant_exit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_calls');
    }
};
