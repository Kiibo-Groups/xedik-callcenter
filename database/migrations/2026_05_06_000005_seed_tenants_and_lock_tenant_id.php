<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $tables = [
        'users', 'omni_queues', 'email_accounts', 'leads',
        'conversations', 'messages', 'attachments', 'canned_responses',
        'scripts', 'campaigns', 'pause_reasons', 'programs', 'agent_sessions',
    ];

    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        DB::transaction(function () {
            // 1. Crear tenants
            $gbpoId = DB::table('tenants')->insertGetId([
                'name'       => 'GBPO',
                'slug'       => 'gbpo',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Backfill — todo lo existente es GBPO
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)
                      ->whereNull('tenant_id')
                      ->update(['tenant_id' => $gbpoId]);
                }
            }
        });

        // 3. NOT NULL — solo en MariaDB/MySQL
        // En SQLite lo omitimos: la restricción se maneja en app layer
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    Schema::table($table, function (Blueprint $t) {
                        $t->foreignId('tenant_id')->nullable(false)->change();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($this->tables as $table) {
                if (Schema::hasTable($table)) {
                    Schema::table($table, function (Blueprint $t) {
                        $t->unsignedBigInteger('tenant_id')->nullable()->change();
                    });
                }
            }
        }

        DB::table('tenants')->truncate();
    }
};
