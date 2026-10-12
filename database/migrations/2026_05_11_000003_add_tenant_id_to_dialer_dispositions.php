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

        // 1. Agregar tenant_id si no existe
        if (!Schema::hasColumn('dialer_dispositions', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE dialer_dispositions ADD COLUMN tenant_id INTEGER DEFAULT 1');
            } else {
                Schema::table('dialer_dispositions', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
                });
            }
            DB::statement('UPDATE dialer_dispositions SET tenant_id = 1 WHERE tenant_id IS NULL');
        }

        // 2. Cambiar UNIQUE name → (tenant_id, name)
        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE dialer_dispositions RENAME TO dialer_dispositions_old');
            DB::statement('
                CREATE TABLE dialer_dispositions (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id   INTEGER NOT NULL DEFAULT 1,
                    name        VARCHAR(255) NOT NULL,
                    color       VARCHAR(7)   NOT NULL DEFAULT \'#374151\',
                    bg_color    VARCHAR(7)   NOT NULL DEFAULT \'#f3f4f6\',
                    is_active   INTEGER      NOT NULL DEFAULT 0,
                    description TEXT,
                    created_at  DATETIME,
                    updated_at  DATETIME,
                    UNIQUE (tenant_id, name)
                )
            ');
            DB::statement('
                INSERT INTO dialer_dispositions (id, tenant_id, name, color, bg_color, is_active, description, created_at, updated_at)
                SELECT id, tenant_id, name, color, bg_color, is_active, description, created_at, updated_at
                FROM dialer_dispositions_old
            ');
            DB::statement('DROP TABLE dialer_dispositions_old');
        } else {
            // MySQL: drop unique en name si existe, agregar compuesto
            Schema::table('dialer_dispositions', function (Blueprint $table) {
                $indexes = collect(DB::select("SHOW INDEX FROM dialer_dispositions"))
                    ->pluck('Key_name')->toArray();
                if (in_array('dialer_dispositions_name_unique', $indexes)) {
                    $table->dropUnique(['name']);
                }
                if (!in_array('dialer_dispositions_tenant_id_name_unique', $indexes)) {
                    $table->unique(['tenant_id', 'name']);
                }
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        Schema::table('dialer_dispositions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['name']);
            $table->dropColumn('tenant_id');
        });
    }
};
