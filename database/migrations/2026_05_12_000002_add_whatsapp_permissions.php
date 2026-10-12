<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'module'     => 'admin',
                'name'       => 'manage_whatsapp_accounts',
                'label'      => 'WhatsApp Accounts',
                'route'      => 'whatsapp-accounts.index',
                'icon'       => 'bi-whatsapp',
                'sort_order' => 15,
            ],
            [
                'module'     => 'admin',
                'name'       => 'manage_whatsapp_templates',
                'label'      => 'WhatsApp Templates',
                'route'      => 'whatsapp-templates.index',
                'icon'       => 'bi-chat-square-text-fill',
                'sort_order' => 16,
            ],
        ];

        foreach ($permissions as $p) {
            Permission::updateOrCreate(['name' => $p['name']], $p);
        }

        // Auto-assign both permissions to every admin role
        $adminRoles = \App\Models\Role::where('name', 'admin')->get();
        $newIds     = Permission::whereIn('name', [
            'manage_whatsapp_accounts',
            'manage_whatsapp_templates',
        ])->pluck('id');

        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching($newIds);
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'manage_whatsapp_accounts',
            'manage_whatsapp_templates',
        ])->delete();
    }
};
