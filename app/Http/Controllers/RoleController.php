<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $roles = Role::withCount('permissions')->with('tenant')->get();
        return view('roles.index', compact('roles', 'isSuperAdmin'));
    }

    public function create(Request $request)
    {
        $tenantId = $this->resolveTenantId($request);

        $permissions = Tenant::findOrFail($tenantId)->enabledPermissions()
            ->orderBy('module')->orderBy('sort_order')->get()->groupBy('module');

        return view('roles.create', compact('permissions'));
    }

    public function store(Request $request)
    {
        $tenantId = $this->resolveTenantId($request);

        $request->validate([
            'name'  => [
                'required', 'string', 'alpha_dash',
                Rule::unique('roles', 'name')->where('tenant_id', $tenantId),
            ],
            'label' => 'required|string',
        ]);

        $role = Role::create([
            'tenant_id' => $tenantId,
            'name'      => strtolower($request->name),
            'label'     => $request->label,
        ]);

        $role->permissions()->sync($this->allowedPermissionIds($tenantId, $request->permissions ?? []));

        return redirect()->route('roles.index')->with('success', 'Role created successfully.');
    }

    public function edit(Role $role)
    {
        $permissions = Tenant::findOrFail($role->tenant_id)->enabledPermissions()
            ->orderBy('module')->orderBy('sort_order')->get()->groupBy('module');
        $rolePermissions = $role->permissions->pluck('id')->toArray();
        return view('roles.edit', compact('role', 'permissions', 'rolePermissions'));
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'label' => 'required|string',
        ]);

        $role->update(['label' => $request->label]);
        $role->permissions()->sync($this->allowedPermissionIds($role->tenant_id, $request->permissions ?? []));

        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
    }

    private function resolveTenantId(Request $request): int
    {
        return auth()->user()->isSuperAdmin()
            ? (int) ($request->tenant_id ?? 1)
            : (int) auth()->user()->tenant_id;
    }

    /**
     * Server-side guard: a role can only end up with permissions the tenant
     * is actually entitled to, regardless of what the client posts.
     */
    private function allowedPermissionIds(int $tenantId, array $requestedIds): array
    {
        $requestedIds = array_map('intval', $requestedIds);
        $allowedIds = Tenant::findOrFail($tenantId)->enabledPermissions()->pluck('permissions.id')->all();

        return array_values(array_intersect($requestedIds, $allowedIds));
    }

    public function destroy(Role $role)
    {
        // Proteger roles default dentro del propio tenant
        if (in_array($role->name, ['admin', 'agent'])) {
            return redirect()->route('roles.index')
                ->with('error', 'Cannot delete default roles.');
        }

        $usersWithRole = \App\Models\User::where('role_id', $role->id)->count();
        if ($usersWithRole > 0) {
            return redirect()->route('roles.index')
                ->with('error', "Cannot delete role — {$usersWithRole} user(s) still have this role. Reassign them first.");
        }

        $role->delete();
        return redirect()->route('roles.index')->with('success', 'Role deleted.');
    }
}
