<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insert([
            'module'     => 'reports',
            'name'       => 'view_dialer_report',
            'label'      => 'Call Log',
            'route'      => 'dialer-report.index',
            'icon'       => 'bi-journal-text',
            'sort_order' => 50,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permId     = DB::table('permissions')->where('name', 'view_dialer_report')->value('id');
        $adminRoles = DB::table('roles')->where('name', 'admin')->pluck('id');

        $rows = [];
        foreach ($adminRoles as $roleId) {
            $rows[] = ['role_id' => $roleId, 'permission_id' => $permId];
        }
        if (!empty($rows)) {
            DB::table('role_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('name', 'view_dialer_report')->value('id');
        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
