<?php
// database/migrations/2026_05_06_000002_add_tenant_id_to_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Order matters: users first because other tables have FKs to users.
    private array $tables = [
        'users',
        'omni_queues',
        'email_accounts',
        'leads',
        'conversations',
        'messages',
        'attachments',
        'canned_responses',
        'scripts',
        'campaigns',
        'pause_reasons',
        'programs',
        'agent_sessions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            if (DB::getDriverName() === 'sqlite') {
                // SQLite is used for functional tests. Adding FK constraints here
                // would rebuild historical tables that contain legacy enum/default
                // definitions incompatible with SQLite rebuilds. Keep the column
                // and index for test behavior; MariaDB keeps the full FK path and
                // remains the production reference for constraints.
                DB::statement("ALTER TABLE {$table} ADD COLUMN tenant_id INTEGER NULL");
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->index('tenant_id', "idx_{$table}_tenant_id");
                });

                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignId('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    ->cascadeOnDelete();

                $t->index('tenant_id', "idx_{$table}_tenant_id");
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (DB::getDriverName() !== 'sqlite') {
                    $t->dropForeign(['tenant_id']);
                }

                $t->dropIndex("idx_{$table}_tenant_id");
                $t->dropColumn('tenant_id');
            });
        }
    }
};
