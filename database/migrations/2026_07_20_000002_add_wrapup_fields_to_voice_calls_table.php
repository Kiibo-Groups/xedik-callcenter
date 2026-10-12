<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical CRM home for Voice wrap-up timing. Populated at wrap-up start /
     * disposition regardless of whether asterisk_linkedid is known yet (outbound
     * Manual Dial receives the linkedid later, via QueueLog). Reconciled into
     * telephony_calls once the linkedid arrives.
     */
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            $table->timestamp('wrapup_started_at')->nullable()->after('ended_at');
            $table->timestamp('wrapup_ended_at')->nullable()->after('wrapup_started_at');
            $table->unsignedInteger('wrapup_seconds')->nullable()->after('wrapup_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            $table->dropColumn([
                'wrapup_started_at',
                'wrapup_ended_at',
                'wrapup_seconds',
            ]);
        });
    }
};
