<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceExtensionRange;
use Illuminate\Http\Request;

class VoiceExtensionRangeController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $ranges = VoiceExtensionRange::withoutGlobalScopes()
            ->with('tenant')
            ->orderBy('tenant_id')
            ->orderBy('range_start')
            ->get();

        $tenants = Tenant::withoutGlobalScopes()->orderBy('name')->get();

        return view('admin.voice.extension-ranges.index', compact('ranges', 'tenants'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'tenant_id'   => 'required|exists:tenants,id',
            'range_start' => 'required|integer|min:100|max:9999',
            'range_end'   => 'required|integer|min:100|max:9999|gte:range_start',
            'label'       => 'nullable|string|max:120',
        ]);

        $tenantId = (int) $validated['tenant_id'];
        $start    = (int) $validated['range_start'];
        $end      = (int) $validated['range_end'];

        // Check overlap within the same tenant's active ranges
        $overlapping = VoiceExtensionRange::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('range_start', '<=', $end)
            ->where('range_end', '>=', $start)
            ->first();

        if ($overlapping) {
            return back()
                ->withInput()
                ->with('error', "Range {$start}–{$end} overlaps with existing range {$overlapping->range_start}–{$overlapping->range_end}" . ($overlapping->label ? " ({$overlapping->label})" : '') . '.');
        }

        VoiceExtensionRange::withoutGlobalScopes()->create([
            'tenant_id'   => $tenantId,
            'range_start' => $start,
            'range_end'   => $end,
            'label'       => $validated['label'] ?? null,
            'is_active'   => true,
        ]);

        return back()->with('success', "Range {$start}–{$end} added.");
    }

    public function update(Request $request, VoiceExtensionRange $voiceExtensionRange)
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'range_start' => 'required|integer|min:100|max:9999',
            'range_end'   => 'required|integer|min:100|max:9999|gte:range_start',
            'label'       => 'nullable|string|max:120',
        ]);

        $start = (int) $validated['range_start'];
        $end   = (int) $validated['range_end'];

        $overlapping = VoiceExtensionRange::withoutGlobalScopes()
            ->where('tenant_id', $voiceExtensionRange->tenant_id)
            ->where('is_active', true)
            ->where('id', '!=', $voiceExtensionRange->id)
            ->where('range_start', '<=', $end)
            ->where('range_end', '>=', $start)
            ->first();

        if ($overlapping) {
            return back()
                ->withInput()
                ->with('error', "Range {$start}–{$end} overlaps with existing range {$overlapping->range_start}–{$overlapping->range_end}.");
        }

        $voiceExtensionRange->update([
            'range_start' => $start,
            'range_end'   => $end,
            'label'       => $validated['label'] ?? null,
        ]);

        return back()->with('success', 'Range updated.');
    }

    public function destroy(VoiceExtensionRange $voiceExtensionRange)
    {
        $this->authorizeSuperAdmin();

        $inUse = \App\Models\VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $voiceExtensionRange->tenant_id)
            ->whereBetween('extension', [$voiceExtensionRange->range_start, $voiceExtensionRange->range_end])
            ->exists();

        if ($inUse) {
            return back()->with('error', 'Cannot delete a range that has active extensions assigned.');
        }

        $voiceExtensionRange->delete();
        return back()->with('success', 'Range deleted.');
    }

    public function toggleActive(VoiceExtensionRange $voiceExtensionRange)
    {
        $this->authorizeSuperAdmin();
        $voiceExtensionRange->update(['is_active' => !$voiceExtensionRange->is_active]);
        return response()->json(['is_active' => $voiceExtensionRange->is_active]);
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
