<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    // permissions table is global (no tenant_id) — same pattern as add_social_accounts_permission
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => 'view_pipeline'],
            [
                'module'     => 'leads',
                'label'      => 'Pipeline',
                'route'      => 'pipeline.index',
                'icon'       => 'bi-kanban',
                'sort_order' => 19,
            ]
        );

        // El Pipeline antes se mostraba a cualquier rol con view_my_leads.
        // Para no perder acceso tras el deploy, se asigna view_pipeline a
        // todos los roles (de todos los tenants) que ya tengan view_my_leads.
        $myLeadsPermission = Permission::where('name', 'view_my_leads')->first();

        if ($myLeadsPermission) {
            $roleIds = $myLeadsPermission->roles()->pluck('roles.id');

            foreach (Role::whereIn('id', $roleIds)->get() as $role) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('name', 'view_pipeline')->delete();
    }
};
