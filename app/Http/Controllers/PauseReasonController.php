<?php

namespace App\Http\Controllers;

use App\Models\PauseReason;
use Illuminate\Http\Request;

class PauseReasonController extends Controller
{
    public function index()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $reasons = PauseReason::with('tenant')->orderBy('sort_order')->get();
        return view('pause_reasons.index', compact('reasons', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'max_minutes' => 'nullable|integer|min:1|max:480',
            'color'       => 'required|string|size:7',
            'sort_order'  => 'integer|min:0',
        ]);

        PauseReason::create([
            'name'        => $request->name,
            'max_minutes' => $request->max_minutes,
            'color'       => $request->color,
            'is_active'   => true,
            'sort_order'  => PauseReason::max('sort_order') + 1,
        ]);

        return redirect()->route('pause-reasons.index')
                         ->with('success', 'Pause reason created.');
    }

    public function update(Request $request, PauseReason $pauseReason)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'max_minutes' => 'nullable|integer|min:1|max:480',
            'color'       => 'required|string|size:7',
        ]);

        $pauseReason->update($request->only('name', 'max_minutes', 'color'));

        return redirect()->route('pause-reasons.index')
                         ->with('success', 'Pause reason updated.');
    }

    public function destroy(PauseReason $pauseReason)
    {
        $pauseReason->delete();
        return redirect()->route('pause-reasons.index')
                         ->with('success', 'Pause reason deleted.');
    }

    public function toggleActive(PauseReason $pauseReason)
    {
       $pauseReason->update(['is_active' => !$pauseReason->is_active]);
        return response()->json(['is_active' => $pauseReason->is_active]);
    }

    public function reorder(Request $request)
    {
        foreach ($request->order as $index => $id) {
            PauseReason::where('id', $id)->update(['sort_order' => $index]);
        }
        return response()->json(['ok' => true]);
    }   
}