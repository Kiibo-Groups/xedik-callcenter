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

        return view('programs.index', compact('programs', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:programs,name',
        ]);

        Program::create([
            'name'      => $validated['name'],
            'slug'      => Str::slug($validated['name']),
            'is_active' => true,
        ]);

        return back()->with('success', "Program \"{$validated['name']}\" created successfully.");
    }

    public function update(Request $request, Program $program)
    {
        abort_unless(auth()->user()->hasPermission('manage_programs'), 403);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100', Rule::unique('programs', 'name')->ignore($program->id)],
            'is_active' => 'nullable|boolean',
        ]);

        $program->update([
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
