<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Insertar permisos del Power Dialer en módulo 'dialer'
        $permissions = [
            [
                'module'     => 'dialer',
                'name'       => 'manage_lead_lists',
                'label'      => 'Lead Lists',
                'route'      => 'lead-lists.index',
                'icon'       => 'bi-list-ul',
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module'     => 'dialer',
                'name'       => 'manage_dialer_campaigns',
                'label'      => 'Campañas Dialer',
                'route'      => 'dialer-campaigns.index',
                'icon'       => 'bi-telephone-outbound',
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'module'     => 'dialer',
                'name'       => 'manage_dialer_dnc',
                'label'      => 'DNC',
                'route'      => 'dialer-dnc.index',
                'icon'       => 'bi-telephone-x',
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('permissions')->insert($permissions);

        // Asignar a todos los roles 'admin' de cada tenant
        $permIds = DB::table('permissions')
            ->whereIn('name', ['manage_lead_lists', 'manage_dialer_campaigns', 'manage_dialer_dnc'])
            ->pluck('id');

        $adminRoles = DB::table('roles')->where('name', 'admin')->pluck('id');

        $pivotRows = [];
        foreach ($adminRoles as $roleId) {
            foreach ($permIds as $permId) {
                $pivotRows[] = ['role_id' => $roleId, 'permission_id' => $permId];
            }
        }

        if (!empty($pivotRows)) {
            DB::table('role_permissions')->insertOrIgnore($pivotRows);
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')
            ->whereIn('name', ['manage_lead_lists', 'manage_dialer_campaigns', 'manage_dialer_dnc'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
    }
};
