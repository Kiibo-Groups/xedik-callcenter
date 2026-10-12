<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DialerDisposition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DialerController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer'), 403);

        $isSuperAdmin = auth()->user()->is_superadmin;
        $dispositions = DialerDisposition::orderBy('is_active', 'desc')->orderBy('name')->get();

        return view('dialer.index', compact('dispositions', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer'), 403);

        $tenantId = auth()->user()->tenant_id;

        $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('dialer_dispositions', 'name')->where('tenant_id', $tenantId),
            ],
            'description' => 'nullable|string|max:255',
            'requires_callback_at' => 'nullable|boolean',
        ]);

        DialerDisposition::create([
            'name'                 => $request->name,
            'description'          => $request->description,
            'requires_callback_at' => $request->boolean('requires_callback_at'),
            'color'                => '#374151',
            'bg_color'             => '#f3f4f6',
            'is_active'            => false,
        ]);

        return back()->with('success', "Disposition \"{$request->name}\" added.");
    }

    public function update(Request $request, DialerDisposition $disposition)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer'), 403);
        $request->validate([
            'name'        => 'required|string|max:100',
            'color'       => 'required|string|max:7',
            'bg_color'    => 'required|string|max:7',
            'description' => 'nullable|string|max:255',
            'requires_callback_at' => 'nullable|boolean',
        ]);
        $disposition->update([
            'name'                 => $request->name,
            'color'                => $request->color,
            'bg_color'             => $request->bg_color,
            'description'          => $request->description,
            'requires_callback_at' => $request->boolean('requires_callback_at'),
        ]);
        return back()->with('success', "Disposition \"{$disposition->name}\" updated.");
    }

    public function toggleActive(DialerDisposition $disposition)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer'), 403);
        $disposition->update(['is_active' => !$disposition->is_active]);
        return response()->json(['success' => true, 'is_active' => $disposition->is_active]);
    }

    public function destroy(DialerDisposition $disposition)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer'), 403);
        $name = $disposition->name;
        $disposition->delete();
        return back()->with('success', "Disposition \"{$name}\" deleted.");
    }
}

