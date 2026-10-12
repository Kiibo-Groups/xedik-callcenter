<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoiceGatewayController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $gateways = VoiceGateway::with('tenant')->orderByRaw('tenant_id IS NULL DESC')->orderBy('name')->get();
        $tenants  = Tenant::withoutGlobalScopes()->orderBy('name')->get();

        return view('admin.voice.gateways.index', compact('gateways', 'tenants'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $tenantIdForScopeRule = $this->tenantIdForScopeRule($request->input('tenant_id'));

        $validated = $request->validate([
            'tenant_id'              => 'nullable|integer|exists:tenants,id',
            'name'                   => [
                'required', 'string', 'max:120',
                Rule::unique('voice_gateways', 'name')->where(
                    fn ($query) => $tenantIdForScopeRule !== null
                        ? $query->where('tenant_id', $tenantIdForScopeRule)
                        : $query->whereNull('tenant_id')
                ),
            ],
            // Not unique: a logical gateway is an outbound route profile, not a
            // unique physical endpoint — multiple gateways (e.g. different
            // country_code/hidden_prefix) may intentionally share one Asterisk
            // trunk. Uniqueness is enforced on `name` per tenant scope instead.
            'asterisk_endpoint_id'   => 'required|string|max:80|regex:/^[a-zA-Z0-9_\-]+$/',
            'hidden_prefix'          => 'nullable|string|max:20|regex:/^[0-9]*$/',
            'country_code'           => 'nullable|string|max:5',
            'national_number_length' => 'nullable|integer|min:7|max:15',
            'is_default'             => 'boolean',
        ]);

        $tenantId = $this->normalizeValidatedTenantId($validated['tenant_id'] ?? null);

        DB::transaction(function () use ($validated, $tenantId): void {
            if (!empty($validated['is_default'])) {
                $this->clearDefaultForScope($tenantId);
            }

            VoiceGateway::create([
                ...$validated,
                'tenant_id'  => $tenantId,
                'is_default' => !empty($validated['is_default']),
                'is_active'  => true,
            ]);
        });

        return back()->with('success', 'Gateway added.');
    }

    public function update(Request $request, VoiceGateway $voiceGateway)
    {
        $this->authorizeSuperAdmin();

        // The requested (possibly new) scope — uniqueness and default-clearing
        // must key off this, not the record's current tenant_id, so changing
        // scope is validated against where the gateway is actually moving to.
        $tenantIdForScopeRule = $this->tenantIdForScopeRule($request->input('tenant_id'));

        $validated = $request->validate([
            'tenant_id'              => 'nullable|integer|exists:tenants,id',
            'name'                   => [
                'required', 'string', 'max:120',
                Rule::unique('voice_gateways', 'name')
                    ->where(
                        fn ($query) => $tenantIdForScopeRule !== null
                            ? $query->where('tenant_id', $tenantIdForScopeRule)
                            : $query->whereNull('tenant_id')
                    )
                    ->ignore($voiceGateway->id),
            ],
            // Not unique — see store() for why asterisk_endpoint_id may be shared.
            'asterisk_endpoint_id'   => 'required|string|max:80|regex:/^[a-zA-Z0-9_\-]+$/',
            'hidden_prefix'          => 'nullable|string|max:20|regex:/^[0-9]*$/',
            'country_code'           => 'nullable|string|max:5',
            'national_number_length' => 'nullable|integer|min:7|max:15',
            'is_default'             => 'boolean',
        ]);

        $tenantId = $this->normalizeValidatedTenantId($validated['tenant_id'] ?? null);

        DB::transaction(function () use ($validated, $tenantId, $voiceGateway): void {
            if (!empty($validated['is_default'])) {
                $this->clearDefaultForScope($tenantId, $voiceGateway->id);
            }

            $voiceGateway->update([
                ...$validated,
                'tenant_id'  => $tenantId,
                'is_default' => !empty($validated['is_default']),
            ]);
        });

        return back()->with('success', 'Gateway updated.');
    }

    public function destroy(VoiceGateway $voiceGateway)
    {
        $this->authorizeSuperAdmin();

        $inUse = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
            ->where('voice_gateway_id', $voiceGateway->id)
            ->exists()
            || \App\Models\Campaign::withoutGlobalScopes()
            ->where('voice_gateway_id', $voiceGateway->id)
            ->exists();

        if ($inUse) {
            return back()->with('error', 'Cannot delete a gateway that is in use by tenants or campaigns. Remove those associations first.');
        }

        $voiceGateway->delete();
        return back()->with('success', 'Gateway deleted.');
    }

    public function toggleActive(VoiceGateway $voiceGateway)
    {
        $this->authorizeSuperAdmin();

        if ($voiceGateway->is_active) {
            $inUse = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
                ->where('voice_gateway_id', $voiceGateway->id)
                ->exists()
                || \App\Models\Campaign::withoutGlobalScopes()
                ->where('voice_gateway_id', $voiceGateway->id)
                ->exists();

            if ($inUse) {
                return response()->json([
                    'error' => 'Cannot deactivate a gateway currently in use by tenants or campaigns.',
                ], 422);
            }
        }

        $voiceGateway->update(['is_active' => !$voiceGateway->is_active]);
        return response()->json(['is_active' => $voiceGateway->is_active]);
    }

    private function clearDefaultForScope(?int $tenantId, ?int $exceptId = null): void
    {
        VoiceGateway::when(
            $tenantId === null,
            fn($q) => $q->whereNull('tenant_id'),
            fn($q) => $q->where('tenant_id', $tenantId)
        )
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    private function tenantIdForScopeRule(mixed $tenantId): ?int
    {
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        if (is_int($tenantId)) {
            return $tenantId;
        }

        if (is_string($tenantId) && ctype_digit($tenantId)) {
            return (int) $tenantId;
        }

        return null;
    }

    private function normalizeValidatedTenantId(mixed $tenantId): ?int
    {
        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        return (int) $tenantId;
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
