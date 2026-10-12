<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Program;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoiceGateway;
use App\Models\VoiceTenantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CampaignController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $campaigns = Campaign::withCount(['leads', 'users'])
            ->with([
                'users'   => fn($q) => $q->orderBy('name'),
                'program',
                'tenant',
            ])
            ->orderBy('name')
            ->get();

        return view('campaigns.index', compact('campaigns', 'isSuperAdmin'));
    }

    public function create()
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        return view('campaigns.create', $this->formData());
    }

    public function edit(Campaign $campaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        $campaign->load('users');

        return view('campaigns.edit', array_merge($this->formData($campaign), compact('campaign')));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        $rules = [
            'name'               => 'required|string|max:100|unique:campaigns,name',
            'program_id'         => 'nullable|exists:programs,id',
            'dialer_pattern'     => 'nullable|string|max:100',
            'color'              => 'required|string|max:7',
            'bg_color'           => 'required|string|max:7',
            'description'        => 'nullable|string|max:255',
            'is_default'         => 'nullable|boolean',
            'outbound_caller_id' => 'nullable|string|max:40|regex:/^[0-9+\-().]*$/',
            'voice_gateway_id'   => 'nullable|integer|exists:voice_gateways,id',
        ];

        if (auth()->user()->isSuperAdmin()) {
            $rules['tenant_id'] = 'required|integer|exists:tenants,id';
        }

        $validated = $request->validate($rules);
        $tenantId = auth()->user()->isSuperAdmin()
            ? (int) $validated['tenant_id']
            : (int) auth()->user()->tenant_id;

        if (!$this->gatewayIsAvailableForTenant($validated['voice_gateway_id'] ?? null, $tenantId)) {
            return back()->withInput()->with('error', 'Selected voice gateway is not available for this tenant.');
        }

        if (!empty($validated['is_default'])) {
            Campaign::where('is_default', true)->update(['is_default' => false]);
        }

        $campaign = Campaign::create([
            ...$validated,
            'tenant_id'   => $tenantId,
            'slug'       => Str::slug($validated['name'], '_'),
            'is_default' => !empty($validated['is_default']),
            'is_active'  => true,
        ]);

        if ($request->filled('agent_ids')) {
            $campaign->users()->sync($request->input('agent_ids'));
        }

        return redirect()->route('campaigns.index')
            ->with('success', "Campaign \"{$campaign->name}\" created successfully.");
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        $validated = $request->validate([
            'name'               => 'required|string|max:100|unique:campaigns,name,' . $campaign->id,
            'program_id'         => 'nullable|exists:programs,id',
            'dialer_pattern'     => 'nullable|string|max:100',
            'color'              => 'required|string|max:7',
            'bg_color'           => 'required|string|max:7',
            'description'        => 'nullable|string|max:255',
            'is_default'         => 'nullable|boolean',
            'is_active'          => 'nullable|boolean',
            'outbound_caller_id' => 'nullable|string|max:40|regex:/^[0-9+\-().]*$/',
            'voice_gateway_id'   => 'nullable|integer|exists:voice_gateways,id',
        ]);

        $tenantId = (int) $campaign->tenant_id;
        if (!$this->gatewayIsAvailableForTenant($validated['voice_gateway_id'] ?? null, $tenantId)) {
            return back()->withInput()->with('error', 'Selected voice gateway is not available for this tenant.');
        }

        if (!empty($validated['is_default'])) {
            Campaign::where('is_default', true)
                ->where('id', '!=', $campaign->id)
                ->update(['is_default' => false]);
        }

        $campaign->update([
            ...$validated,
            'is_default' => !empty($validated['is_default']),
            'is_active'  => !empty($validated['is_active'] ?? false),
        ]);

        $campaign->users()->sync($request->input('agent_ids', []));

        return redirect()->route('campaigns.index')
            ->with('success', "Campaign \"{$campaign->name}\" updated successfully.");
    }

    public function destroy(Campaign $campaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);

        $leadsCount = $campaign->leads()->count();

        if ($leadsCount > 0) {
            return back()->with('error', "Cannot delete \"{$campaign->name}\" — it has {$leadsCount} leads assigned.");
        }

        $campaign->users()->detach();
        $campaign->delete();

        return back()->with('success', 'Campaign deleted.');
    }

    public function toggleActive(Campaign $campaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_campaigns'), 403);
        $campaign->update(['is_active' => !$campaign->is_active]);
        return response()->json(['success' => true, 'is_active' => $campaign->is_active]);
    }

    // ── Shared form data ──────────────────────────────────────────────────────

    private function formData(?Campaign $campaign = null): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $tenants = $isSuperAdmin
            ? Tenant::where('is_active', true)->orderBy('name')->get()
            : collect();

        $selectedTenantId = old(
            'tenant_id',
            $campaign?->tenant_id ?? ($isSuperAdmin ? $tenants->first()?->id : $user->tenant_id)
        );
        $selectedTenantId = $selectedTenantId ? (int) $selectedTenantId : null;

        $programs = Program::where('is_active', true)->orderBy('name')->get();

        $agents = User::whereHas('role', fn($q) => $q->where('name', 'agent'))
            ->where('is_active', true)
            ->when($selectedTenantId, fn($q) => $q->where('tenant_id', $selectedTenantId))
            ->orderBy('name')
            ->get();

        $voiceGateways = VoiceGateway::where('is_active', true)
            ->when(!$isSuperAdmin, fn($q) => $q->where(fn($sub) => $sub
                ->whereNull('tenant_id')
                ->orWhere('tenant_id', $selectedTenantId)
            ))
            ->orderBy('name')
            ->get();

        $tenantVoiceEnabled = (bool) (VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $selectedTenantId)
            ->value('voice_enabled') ?? false);

        return compact(
            'programs',
            'agents',
            'voiceGateways',
            'tenantVoiceEnabled',
            'isSuperAdmin',
            'tenants',
            'selectedTenantId'
        );
    }

    private function gatewayIsAvailableForTenant(mixed $gatewayId, int $tenantId): bool
    {
        if (empty($gatewayId)) {
            return true;
        }

        $gateway = VoiceGateway::find($gatewayId);

        return $gateway !== null && $gateway->isAccessibleByTenant($tenantId);
    }
}
