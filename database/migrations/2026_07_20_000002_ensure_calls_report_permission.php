<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'view_calls_report'],
            [
                'module' => 'reports',
                'label' => 'Calls Report',
                'route' => 'reports.calls.index',
                'icon' => 'bi-telephone-inbound-fill',
                'sort_order' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = (int) DB::table('permissions')
            ->where('name', 'view_calls_report')
            ->value('id');

        if (!$permissionId || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $adminRoleIds = DB::table('roles')->where('name', 'admin')->pluck('id');
        foreach ($adminRoleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive permission catalog repair.
    }
};
