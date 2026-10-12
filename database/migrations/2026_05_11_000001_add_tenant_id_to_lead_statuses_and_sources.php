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

        // --- lead_statuses ---
        if (!Schema::hasColumn('lead_statuses', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE lead_statuses ADD COLUMN tenant_id INTEGER DEFAULT 1');
            } else {
                Schema::table('lead_statuses', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
                });
            }
            DB::statement('UPDATE lead_statuses SET tenant_id = 1 WHERE tenant_id IS NULL');
        }

        // --- lead_sources ---
        if (!Schema::hasColumn('lead_sources', 'tenant_id')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE lead_sources ADD COLUMN tenant_id INTEGER DEFAULT 1');
            } else {
                Schema::table('lead_sources', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
                });
            }
            DB::statement('UPDATE lead_sources SET tenant_id = 1 WHERE tenant_id IS NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if (Schema::hasColumn('lead_statuses', 'tenant_id')) {
            Schema::table('lead_statuses', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        }
        if (Schema::hasColumn('lead_sources', 'tenant_id')) {
            Schema::table('lead_sources', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        }
    }
};
