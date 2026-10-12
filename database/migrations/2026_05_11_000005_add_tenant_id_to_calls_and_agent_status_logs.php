<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── calls ──────────────────────────────────────────────────────────
        if (!Schema::hasColumn('calls', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE calls ADD COLUMN tenant_id INTEGER DEFAULT NULL');
            } else {
                Schema::table('calls', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                    $table->index('tenant_id', 'idx_calls_tenant_id');
                });
            }
            // Backfill: inherit tenant from the lead's agent (user)
            DB::statement('
                UPDATE calls
                SET tenant_id = (
                    SELECT users.tenant_id
                    FROM users
                    WHERE users.id = calls.agent_id
                    LIMIT 1
                )
                WHERE tenant_id IS NULL
            ');
        }

        // ── agent_status_logs ──────────────────────────────────────────────
        if (!Schema::hasColumn('agent_status_logs', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE agent_status_logs ADD COLUMN tenant_id INTEGER DEFAULT NULL');
            } else {
                Schema::table('agent_status_logs', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                    $table->index('tenant_id', 'idx_agent_status_logs_tenant_id');
                });
            }
            // Backfill: inherit tenant from the user
            DB::statement('
                UPDATE agent_status_logs
                SET tenant_id = (
                    SELECT users.tenant_id
                    FROM users
                    WHERE users.id = agent_status_logs.user_id
                    LIMIT 1
                )
                WHERE tenant_id IS NULL
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'sqlite') {
            if (Schema::hasColumn('calls', 'tenant_id')) {
                Schema::table('calls', function (Blueprint $table) {
                    $table->dropIndex('idx_calls_tenant_id');
                    $table->dropColumn('tenant_id');
                });
            }
            if (Schema::hasColumn('agent_status_logs', 'tenant_id')) {
                Schema::table('agent_status_logs', function (Blueprint $table) {
                    $table->dropIndex('idx_agent_status_logs_tenant_id');
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
