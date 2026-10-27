<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $programs = Program::withCount('campaigns')->with('tenant')->orderBy('name')->get();
        $tenants = $isSuperAdmin ? \App\Models\Tenant::orderBy('name')->get() : collect();

        return view('programs.index', compact('programs', 'isSuperAdmin', 'tenants'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $rules = [
            'name' => 'required|string|max:100',
        ];

        if (auth()->user()->isSuperAdmin()) {
            $rules['tenant_id'] = 'required|integer|exists:tenants,id';
        }

        $validated = $request->validate($rules);
        $tenantId = auth()->user()->isSuperAdmin()
            ? (int) $validated['tenant_id']
            : (int) auth()->user()->tenant_id;

        $exists = Program::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['name' => 'A program with this name already exists for this tenant.']);
        }

        Program::create([
            'tenant_id' => $tenantId,
            'name'      => $validated['name'],
            'slug'      => Str::slug($validated['name']),
            'is_active' => true,
        ]);

        return back()->with('success', "Program \"{$validated['name']}\" created successfully.");
    }

    public function update(Request $request, Program $program)
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $rules = [
            'name'      => 'required|string|max:100',
            'is_active' => 'nullable|boolean',
        ];

        if (auth()->user()->isSuperAdmin()) {
            $rules['tenant_id'] = 'required|integer|exists:tenants,id';
        }

        $validated = $request->validate($rules);
        $tenantId = auth()->user()->isSuperAdmin()
            ? (int) $validated['tenant_id']
            : (int) $program->tenant_id;

        $exists = Program::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', $validated['name'])
            ->where('id', '!=', $program->id)
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['name' => 'A program with this name already exists for this tenant.']);
        }

        $program->update([
            'tenant_id' => $tenantId,
            'name'      => $validated['name'],
            'slug'      => Str::slug($validated['name']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', "Program \"{$program->name}\" updated successfully.");
    }

    public function destroy(Program $program)
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $activeCampaigns = $program->campaigns()->where('is_active', true)->count();

        if ($activeCampaigns > 0) {
            return back()->with('error', "Cannot delete \"{$program->name}\" — it has {$activeCampaigns} active campaign(s) assigned.");
        }

        $program->delete();

        return back()->with('success', 'Program deleted.');
    }
}
