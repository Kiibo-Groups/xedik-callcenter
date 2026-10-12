<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $permissions = Permission::orderBy('module')->orderBy('sort_order')->get()->groupBy('module');
        return view('permissions.index', compact('permissions'));
    }

    public function create()
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return view('permissions.create');
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $request->validate([
            'module'     => 'required|string',
            'name'       => 'required|string|unique:permissions,name|alpha_dash',
            'label'      => 'required|string',
            'route'      => 'required|string',
            'icon'       => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        Permission::create([
            'module'     => strtolower($request->module),
            'name'       => strtolower($request->name),
            'label'      => $request->label,
            'route'      => $request->route,
            'icon'       => $request->icon ?? 'bi-circle',
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return redirect()->route('permissions.index')->with('success', 'Permission created.');
    }

    public function edit(Permission $permission)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return view('permissions.edit', compact('permission'));
    }

    public function update(Request $request, Permission $permission)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $request->validate([
            'module'     => 'required|string',
            'label'      => 'required|string',
            'route'      => 'required|string',
            'icon'       => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $permission->update([
            'module'     => strtolower($request->module),
            'label'      => $request->label,
            'route'      => $request->route,
            'icon'       => $request->icon ?? 'bi-circle',
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return redirect()->route('permissions.index')->with('success', 'Permission updated.');
    }

    public function destroy(Permission $permission)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $protected = ['manage_users', 'manage_roles', 'manage_permissions'];
        if (in_array($permission->name, $protected)) {
            return redirect()->route('permissions.index')
                ->with('error', 'This permission cannot be deleted.');
        }
        $permission->delete();
        return redirect()->route('permissions.index')->with('success', 'Permission deleted.');
    }
}