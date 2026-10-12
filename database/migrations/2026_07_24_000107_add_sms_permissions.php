<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    /**
     * Provisioning de sms_provider_accounts (credenciales de proveedor) y sms_channels
     * es superadmin-only, gate inline en el controller (patrón admin/voice) — no
     * requiere fila de permiso. Solo la asignación de queue (tenant-admin) y los
     * templates son delegables al tenant.
     */
    public function up(): void
    {
        $permissions = [
            [
                'module'     => 'admin',
                'name'       => 'manage_sms_channels',
                'label'      => 'SMS Numbers',
                'route'      => 'sms-channels.index',
                'icon'       => 'bi-chat-dots-fill',
                'sort_order' => 17,
            ],
            [
                'module'     => 'admin',
                'name'       => 'manage_sms_templates',
                'label'      => 'SMS Templates',
                'route'      => 'sms-templates.index',
                'icon'       => 'bi-chat-square-text',
                'sort_order' => 18,
            ],
        ];

        foreach ($permissions as $p) {
            Permission::updateOrCreate(['name' => $p['name']], $p);
        }

        $adminRoles = \App\Models\Role::where('name', 'admin')->get();
        $newIds     = Permission::whereIn('name', [
            'manage_sms_channels',
            'manage_sms_templates',
        ])->pluck('id');

        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching($newIds);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'manage_sms_channels',
            'manage_sms_templates',
        ])->delete();
    }
};
