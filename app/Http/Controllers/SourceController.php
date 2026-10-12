<?php

namespace App\Http\Controllers;

use App\Models\LeadSource;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $sources = LeadSource::withCount('leads')->with('tenant')->orderBy('order')->get();
        return view('sources.index', compact('sources', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);

        $request->validate([
            'name' => 'required|string|max:100|unique:lead_sources,name',
        ]);

        LeadSource::create([
            'name'      => $request->name,
            'is_active' => true,
            'order'     => LeadSource::max('order') + 1,
        ]);

        return back()->with('success', "Source \"{$request->name}\" created.");
    }

    public function update(Request $request, LeadSource $source)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);

        $request->validate([
            'name' => 'required|string|max:100|unique:lead_sources,name,' . $source->id,
        ]);

        $source->update(['name' => $request->name]);
        return back()->with('success', "Source updated.");
    }

    public function toggleActive(LeadSource $source)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);
        $source->update(['is_active' => !$source->is_active]);
        return response()->json(['success' => true, 'is_active' => $source->is_active]);
    }

    public function destroy(LeadSource $source)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);

        if ($source->leads_count > 0) {
            return back()->with('error', "Cannot delete \"{$source->name}\" — it has {$source->leads_count} leads assigned.");
        }

        $source->delete();
        return back()->with('success', "Source deleted.");
    }

    public function reorder(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_lead_sources'), 403);
        $request->validate(['order' => 'required|array']);
        foreach ($request->order as $index => $id) {
            LeadSource::where('id', $id)->update(['order' => $index + 1]);
        }
        return response()->json(['success' => true]);
    }
}
