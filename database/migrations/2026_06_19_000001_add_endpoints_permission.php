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
            ['name' => 'manage_endpoints'],
            [
                'module'     => 'admin',
                'label'      => 'Endpoints',
                'route'      => 'endpoints.index',
                'icon'       => 'bi-plug-fill',
                'sort_order' => 56,
            ]
        );

        $adminRoles = Role::where('name', 'admin')->get();
        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'manage_endpoints')->delete();
    }
};
