<?php

namespace App\Http\Controllers;

use App\Models\LeadStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $statuses = LeadStatus::withCount('leads')->with('tenant')->orderBy('order')->get();
        return view('statuses.index', compact('statuses', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'color'            => 'required|string|size:7',
            'bg_color'         => 'required|string|size:7',
            'description'      => 'nullable|string|max:255',
            'is_default'       => 'nullable|boolean',
            'show_in_pipeline' => 'nullable|boolean',
            'is_won'           => 'nullable|boolean',
            'is_lost'          => 'nullable|boolean',
        ]);

        $slug = LeadStatus::makeSlug($validated['name']);

        // Ensure unique slug
        $base = $slug;
        $i = 1;
        while (LeadStatus::where('slug', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        // Si se marca como default, quitar default de los demás
        if (!empty($validated['is_default'])) {
            LeadStatus::where('is_default', true)->update(['is_default' => false]);
        }

        $order = LeadStatus::max('order') + 1;

        // is_won e is_lost son mutuamente excluyentes — si llegan ambos
        // marcados, is_won tiene prioridad.
        $isWon  = !empty($validated['is_won']);
        $isLost = !$isWon && !empty($validated['is_lost']);

        $status = LeadStatus::create([
            'name'             => $validated['name'],
            'slug'             => $slug,
            'color'            => $validated['color'],
            'bg_color'         => $validated['bg_color'],
            'description'      => $validated['description'] ?? null,
            'is_default'       => !empty($validated['is_default']),
            'order'            => $order,
            'is_active'        => true,
            'show_in_pipeline' => !empty($validated['show_in_pipeline']),
            'is_won'           => $isWon,
            'is_lost'          => $isLost,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $status->id, 'slug' => $status->slug], 201);
        }

        return back()->with('success', "Status \"{$validated['name']}\" created.");
    }

    public function update(Request $request, LeadStatus $status)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'color'            => 'required|string|size:7',
            'bg_color'         => 'required|string|size:7',
            'description'      => 'nullable|string|max:255',
            'is_default'       => 'nullable|boolean',
            'show_in_pipeline' => 'nullable|boolean',
            'is_won'           => 'nullable|boolean',
            'is_lost'          => 'nullable|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            LeadStatus::where('is_default', true)->where('id', '!=', $status->id)
                      ->update(['is_default' => false]);
        }

        // is_won e is_lost son mutuamente excluyentes — si llegan ambos
        // marcados, is_won tiene prioridad.
        $isWon  = !empty($validated['is_won']);
        $isLost = !$isWon && !empty($validated['is_lost']);

        $status->update([
            'name'             => $validated['name'],
            'color'            => $validated['color'],
            'bg_color'         => $validated['bg_color'],
            'description'      => $validated['description'] ?? null,
            'is_default'       => !empty($validated['is_default']),
            'show_in_pipeline' => !empty($validated['show_in_pipeline']),
            'is_won'           => $isWon,
            'is_lost'          => $isLost,
        ]);

        return back()->with('success', "Status \"{$status->name}\" updated.");
    }

    public function toggleActive(LeadStatus $status)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $status->update(['is_active' => !$status->is_active]);

        return response()->json([
            'success'   => true,
            'is_active' => $status->is_active,
        ]);
    }

    public function reorder(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $request->validate(['order' => 'required|array']);

        foreach ($request->order as $index => $id) {
            LeadStatus::where('id', $id)->update(['order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, LeadStatus $status)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_statuses'), 403);

        $leadsCount = $status->leads()->count();

        if ($leadsCount > 0) {
            $message = "Cannot delete: {$leadsCount} lead" . ($leadsCount === 1 ? ' is' : 's are') . " using this status. Reassign them first.";

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        $status->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', "Status deleted.");
    }
}
