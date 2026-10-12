<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceCallerId;
use App\Models\VoiceGateway;
use Illuminate\Http\Request;

class VoiceCallerIdController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $callerIds = VoiceCallerId::withoutGlobalScopes()
            ->with(['tenant', 'voiceGateway'])
            ->orderBy('tenant_id')
            ->orderBy('number')
            ->get();

        $tenants  = Tenant::withoutGlobalScopes()->orderBy('name')->get();
        $gateways = VoiceGateway::where('is_active', true)->orderBy('name')->get();

        return view('admin.voice.caller-ids.index', compact('callerIds', 'tenants', 'gateways'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'tenant_id'        => 'required|exists:tenants,id',
            'voice_gateway_id' => 'nullable|integer|exists:voice_gateways,id',
            'name'             => 'nullable|string|max:120',
            'number'           => ['required', 'string', 'max:40', 'regex:/^\+?\d{7,15}$/'],
            'is_active'        => 'boolean',
            'is_verified'      => 'boolean',
        ]);

        // Normalize: strip non-digits (keep leading + for E.164)
        $validated['number'] = $this->normalizeNumber($validated['number']);

        VoiceCallerId::withoutGlobalScopes()->create([
            'tenant_id'        => (int) $validated['tenant_id'],
            'voice_gateway_id' => isset($validated['voice_gateway_id']) ? (int) $validated['voice_gateway_id'] : null,
            'name'             => $validated['name'] ?? null,
            'number'           => $validated['number'],
            'is_active'        => $request->boolean('is_active', true),
            'is_verified'      => $request->boolean('is_verified', true),
        ]);

        return back()->with('success', 'Caller ID added.');
    }

    public function update(Request $request, VoiceCallerId $voiceCallerId)
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'voice_gateway_id' => 'nullable|integer|exists:voice_gateways,id',
            'name'             => 'nullable|string|max:120',
            'number'           => ['required', 'string', 'max:40', 'regex:/^\+?\d{7,15}$/'],
            'is_active'        => 'boolean',
            'is_verified'      => 'boolean',
        ]);

        $validated['number'] = $this->normalizeNumber($validated['number']);

        // If number changed, ensure uniqueness within the tenant
        if ($validated['number'] !== $voiceCallerId->number) {
            $exists = VoiceCallerId::withoutGlobalScopes()
                ->where('tenant_id', $voiceCallerId->tenant_id)
                ->where('number', $validated['number'])
                ->where('id', '!=', $voiceCallerId->id)
                ->exists();

            if ($exists) {
                return back()->with('error', 'This number already exists for this tenant.');
            }
        }

        $voiceCallerId->update([
            'voice_gateway_id' => isset($validated['voice_gateway_id']) ? (int) $validated['voice_gateway_id'] : null,
            'name'             => $validated['name'] ?? null,
            'number'           => $validated['number'],
            'is_active'        => $request->boolean('is_active', true),
            'is_verified'      => $request->boolean('is_verified', true),
        ]);

        return back()->with('success', 'Caller ID updated.');
    }

    public function destroy(VoiceCallerId $voiceCallerId)
    {
        $this->authorizeSuperAdmin();

        $inUse = $voiceCallerId->voiceExtensions()->exists();

        if ($inUse) {
            return back()->with('error', 'Cannot delete a Caller ID that is assigned to one or more extensions. Reassign them first.');
        }

        $voiceCallerId->delete();
        return back()->with('success', 'Caller ID deleted.');
    }

    public function toggleActive(VoiceCallerId $voiceCallerId)
    {
        $this->authorizeSuperAdmin();

        $voiceCallerId->update(['is_active' => !$voiceCallerId->is_active]);
        return response()->json(['is_active' => $voiceCallerId->is_active]);
    }

    private function normalizeNumber(string $number): string
    {
        // Preserve leading + for E.164; strip everything else
        $hasPlus = str_starts_with(trim($number), '+');
        $digits  = preg_replace('/\D/', '', $number);
        return $hasPlus ? "+{$digits}" : $digits;
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
