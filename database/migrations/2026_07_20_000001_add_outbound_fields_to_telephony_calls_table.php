<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telephony_calls', function (Blueprint $table): void {
            // CRM correlation (populated for outbound from OUTBOUND_START; nullable for inbound).
            $table->unsignedBigInteger('conversation_id')->nullable()->after('linkedid');
            $table->char('call_uuid', 36)->nullable()->after('conversation_id');

            // Direction/mode of the call.
            $table->string('direction', 20)->nullable()->after('call_uuid');
            $table->string('call_mode', 20)->nullable()->after('direction');

            // Outbound technical detail.
            $table->string('dial_prefix', 20)->nullable()->after('caller_number');
            $table->string('hangup_cause', 20)->nullable()->after('exit_reason');

            // Timing.
            $table->timestamp('started_at')->nullable()->after('entered_queue_at');
            $table->unsignedInteger('ring_seconds')->nullable()->after('wait_seconds');

            $table->index(['tenant_id', 'conversation_id'], 'tc_tenant_conversation_idx');
            $table->index(['tenant_id', 'call_uuid'], 'tc_tenant_call_uuid_idx');
            $table->index(['tenant_id', 'direction'], 'tc_tenant_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telephony_calls', function (Blueprint $table): void {
            $table->dropIndex('tc_tenant_conversation_idx');
            $table->dropIndex('tc_tenant_call_uuid_idx');
            $table->dropIndex('tc_tenant_direction_idx');

            $table->dropColumn([
                'conversation_id',
                'call_uuid',
                'direction',
                'call_mode',
                'dial_prefix',
                'hangup_cause',
                'started_at',
                'ring_seconds',
            ]);
        });
    }
};
