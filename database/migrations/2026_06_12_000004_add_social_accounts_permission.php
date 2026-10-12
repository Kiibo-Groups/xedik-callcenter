<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    // permissions table is global (no tenant_id) — same pattern as add_webchat_permissions
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => 'manage_social_accounts'],
            [
                'module'     => 'admin',
                'label'      => 'Social Network',
                'route'      => 'social-accounts.index',
                'icon'       => 'bi-share-fill',
                'sort_order' => 18,
            ]
        );

        // Auto-assign to every admin role across all tenants
        $adminRoles = Role::where('name', 'admin')->get();
        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'manage_social_accounts')->delete();
    }
};
