<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => 'manage_access_tokens'],
            [
                'module'     => 'dialer',
                'label'      => 'Access Tokens',
                'route'      => 'access-tokens.index',
                'icon'       => 'bi-key-fill',
                'sort_order' => 55,
            ]
        );

        $adminRoles = Role::where('name', 'admin')->get();
        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'manage_access_tokens')->delete();
    }
};
