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
            $table->string('disposition')->nullable()->after('exit_reason');
            $table->timestamp('wrapup_started_at')->nullable()->after('disposition');
            $table->timestamp('wrapup_ended_at')->nullable()->after('wrapup_started_at');
            $table->unsignedInteger('wrapup_seconds')->nullable()->after('wrapup_ended_at');

            $table->index(['tenant_id', 'disposition'], 'tc_tenant_disposition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telephony_calls', function (Blueprint $table): void {
            $table->dropIndex('tc_tenant_disposition_idx');
            $table->dropColumn([
                'disposition',
                'wrapup_started_at',
                'wrapup_ended_at',
                'wrapup_seconds',
            ]);
        });
    }
};
