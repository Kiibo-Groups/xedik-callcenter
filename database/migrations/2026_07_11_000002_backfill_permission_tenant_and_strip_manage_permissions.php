<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills permission_tenant so every existing tenant keeps exactly the
 * effective permission set it already has today (nobody loses access on
 * day 1). From this point forward, granting new permissions to a tenant is
 * an explicit superadmin action — nothing is enabled by default.
 *
 * Also strips `manage_permissions` from all tenant-scoped roles: Permission
 * CRUD becomes superadmin-only, so no tenant role needs this anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->select('roles.tenant_id', 'role_permissions.permission_id')
            ->distinct()
            ->get();

        $now = now();
        foreach ($rows as $row) {
            DB::table('permission_tenant')->insertOrIgnore([
                'tenant_id'     => $row->tenant_id,
                'permission_id' => $row->permission_id,
                'enabled_at'    => $now,
            ]);
        }

        $managePermissionsId = DB::table('permissions')->where('name', 'manage_permissions')->value('id');
        if ($managePermissionsId) {
            DB::table('role_permissions')->where('permission_id', $managePermissionsId)->delete();
            DB::table('permission_tenant')->where('permission_id', $managePermissionsId)->delete();
        }
    }

    public function down(): void
    {
        DB::table('permission_tenant')->truncate();
    }
};
