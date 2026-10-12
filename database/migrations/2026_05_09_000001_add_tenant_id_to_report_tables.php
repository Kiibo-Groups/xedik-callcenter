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

        // ── conversation_reports ──────────────────────────────────────────
        if (!Schema::hasColumn('conversation_reports', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE conversation_reports ADD COLUMN tenant_id INTEGER DEFAULT 1');
            } else {
                Schema::table('conversation_reports', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
                });
            }
            DB::statement('UPDATE conversation_reports SET tenant_id = 1 WHERE tenant_id IS NULL');
        }

        // ── message_reports ───────────────────────────────────────────────
        if (Schema::hasTable('message_reports') && !Schema::hasColumn('message_reports', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE message_reports ADD COLUMN tenant_id INTEGER DEFAULT 1');
            } else {
                Schema::table('message_reports', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
                });
            }
            DB::statement('UPDATE message_reports SET tenant_id = 1 WHERE tenant_id IS NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return; // SQLite no soporta DROP COLUMN en versiones antiguas
        }

        if (Schema::hasColumn('conversation_reports', 'tenant_id')) {
            Schema::table('conversation_reports', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        }

        if (Schema::hasTable('message_reports') && Schema::hasColumn('message_reports', 'tenant_id')) {
            Schema::table('message_reports', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        }
    }
};
