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
            ['name' => 'view_voice_dashboard'],
            [
                'module' => 'reports',
                'label' => 'Dashboard',
                'route' => 'supervisor.dashboard.index',
                'icon' => 'bi-bar-chart-line',
                'sort_order' => 14,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = (int) DB::table('permissions')
            ->where('name', 'view_voice_dashboard')
            ->value('id');

        if (!$permissionId || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'supervisor'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
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
