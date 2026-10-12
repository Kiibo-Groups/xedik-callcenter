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
            ['name' => 'manage_conversation_automation'],
            [
                'module'     => 'admin',
                'label'      => 'Conversational Automation',
                'route'      => 'admin.conversation-automation.index',
                'icon'       => 'bi-robot',
                'sort_order' => 19,
            ]
        );

        $adminRoles = Role::where('name', 'admin')->get();
        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'manage_conversation_automation')->delete();
    }
};
