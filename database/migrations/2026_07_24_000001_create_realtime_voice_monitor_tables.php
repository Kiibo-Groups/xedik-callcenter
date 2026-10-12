<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_voice_agent_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('pbx_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('external_queue_name', 128);
            $table->string('member_name', 128);
            $table->string('extension', 64)->nullable();
            $table->string('interface', 160);
            $table->string('state_interface', 160)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->boolean('in_call')->default(false);
            $table->boolean('paused')->default(false);
            $table->string('pause_reason', 128)->nullable();
            $table->string('connection_state', 32);
            $table->string('voice_state', 32);
            $table->string('device_state', 32);
            $table->timestamp('state_since');
            $table->timestamp('connected_since')->nullable();
            $table->unsignedInteger('calls_taken')->default(0);
            $table->timestamp('last_call_at')->nullable();
            $table->timestamp('last_pause_at')->nullable();
            $table->timestamp('login_time_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['pbx_id', 'tenant_id', 'external_queue_name', 'member_name', 'interface'],
                'rt_voice_agents_unique'
            );
            $table->index(['tenant_id', 'omni_queue_id'], 'rt_voice_agents_queue_idx');
            $table->index(['tenant_id', 'user_id'], 'rt_voice_agents_user_idx');
            $table->index(['pbx_id', 'last_seen_at'], 'rt_voice_agents_seen_idx');
        });

        Schema::create('realtime_voice_queue_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('pbx_id');
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('external_queue_name', 128);
            $table->unsignedInteger('calls_waiting')->default(0);
            $table->unsignedInteger('hold_time')->default(0);
            $table->unsignedInteger('talk_time')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('abandoned')->default(0);
            $table->unsignedInteger('service_level')->default(0);
            $table->decimal('service_level_perf', 8, 2)->default(0);
            $table->integer('weight')->default(0);
            $table->unsignedInteger('available_agents')->default(0);
            $table->unsignedInteger('busy_agents')->default(0);
            $table->unsignedInteger('paused_agents')->default(0);
            $table->unsignedInteger('unavailable_agents')->default(0);
            $table->unsignedInteger('longest_wait_seconds')->default(0);
            $table->timestamp('last_seen_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['pbx_id', 'tenant_id', 'external_queue_name'], 'rt_voice_queues_unique');
            $table->index(['tenant_id', 'omni_queue_id'], 'rt_voice_queues_queue_idx');
            $table->index(['pbx_id', 'last_seen_at'], 'rt_voice_queues_seen_idx');
        });

        Schema::create('realtime_voice_waiting_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('pbx_id');
            $table->string('interaction_key', 160)->unique();
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('external_queue_name', 128);
            $table->string('uniqueid', 128);
            $table->string('channel', 160);
            $table->string('caller_number', 64);
            $table->string('caller_name', 128)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('waiting_since');
            $table->unsignedInteger('wait_seconds')->default(0);
            $table->string('status', 32)->default('waiting');
            $table->timestamp('last_seen_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'rt_waiting_calls_tenant_status_idx');
            $table->index(['pbx_id', 'status'], 'rt_waiting_calls_pbx_status_idx');
            $table->index(['tenant_id', 'omni_queue_id'], 'rt_waiting_calls_queue_idx');
        });

        Schema::create('realtime_pbx_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('pbx_id');
            $table->unsignedBigInteger('sequence');
            $table->timestamp('generated_at');
            $table->timestamp('received_at');
            $table->unsignedInteger('queues_count')->default(0);
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('waiting_calls_count')->default(0);
            $table->string('status', 32);
            $table->unsignedInteger('processing_ms')->default(0);
            $table->string('payload_hash', 64);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['pbx_id', 'sequence'], 'rt_pbx_snapshots_unique');
            $table->index(['pbx_id', 'generated_at'], 'rt_pbx_snapshots_generated_idx');
            $table->index('payload_hash', 'rt_pbx_snapshots_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_pbx_snapshots');
        Schema::dropIfExists('realtime_voice_waiting_calls');
        Schema::dropIfExists('realtime_voice_queue_states');
        Schema::dropIfExists('realtime_voice_agent_states');
    }
};
