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
            ['name' => 'manage_scripts'],
            [
                'module' => 'admin',
                'label' => 'Scripts',
                'route' => 'scripts.index',
                'icon' => 'bi-file-text-fill',
                'sort_order' => 24,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = (int) DB::table('permissions')
            ->where('name', 'manage_scripts')
            ->value('id');

        if (!$permissionId) {
            return;
        }

        $campaignPermissionId = (int) DB::table('permissions')
            ->where('name', 'manage_campaigns')
            ->value('id');

        $tenantIds = collect();

        if ($campaignPermissionId && Schema::hasTable('permission_tenant')) {
            $tenantIds = $tenantIds->merge(
                DB::table('permission_tenant')
                    ->where('permission_id', $campaignPermissionId)
                    ->pluck('tenant_id')
            );
        }

        if ($campaignPermissionId && Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $tenantIds = $tenantIds->merge(
                DB::table('roles')
                    ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
                    ->where('roles.name', 'admin')
                    ->whereNotNull('roles.tenant_id')
                    ->where('role_permissions.permission_id', $campaignPermissionId)
                    ->pluck('roles.tenant_id')
            );
        }

        $tenantIds = $tenantIds
            ->filter(fn ($tenantId) => $tenantId !== null)
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();

        if (Schema::hasTable('permission_tenant')) {
            foreach ($tenantIds as $tenantId) {
                DB::table('permission_tenant')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'permission_id' => $permissionId,
                    'enabled_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $adminRoleIds = DB::table('roles')
                ->where('name', 'admin')
                ->whereNotNull('tenant_id')
                ->when($tenantIds->isNotEmpty(), fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                ->pluck('id');

            foreach ($adminRoleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => (int) $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration repairs a missing
        // catalog permission in deployed environments and must not remove a
        // legitimate existing permission or tenant entitlement on rollback.
    }
};
