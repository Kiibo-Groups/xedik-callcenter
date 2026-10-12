<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceGateway;
use App\Models\VoiceTenantSetting;
use Illuminate\Http\Request;

class VoiceTenantSettingController extends Controller
{
    public function index()
    {
        $this->authorizeSuperAdmin();

        $tenants = Tenant::with(['voiceTenantSetting.voiceGateway', 'voiceExtensionRanges'])
            ->withoutGlobalScopes()
            ->orderBy('name')
            ->get()
            ->each(function ($tenant) {
                // Ensure every tenant has a settings record
                if (!$tenant->voiceTenantSetting) {
                    $tenant->setRelation('voiceTenantSetting', VoiceTenantSetting::withoutGlobalScopes()->firstOrCreate(
                        ['tenant_id' => $tenant->id],
                        ['voice_enabled' => false, 'session_ttl_seconds' => 28800, 'max_sessions_per_agent' => 1]
                    ));
                }
            });

        $gateways = VoiceGateway::where('is_active', true)->orderBy('name')->get();

        return view('admin.voice.tenant-settings.index', compact('tenants', 'gateways'));
    }

    public function update(Request $request, Tenant $tenant)
    {
        $this->authorizeSuperAdmin();

        $validated = $request->validate([
            'voice_enabled'          => 'boolean',
            'preview_dialer_enabled' => 'boolean',
            'default_caller_id'      => 'nullable|string|max:40|regex:/^[0-9+\-().]*$/',
            'voice_gateway_id'       => 'nullable|integer|exists:voice_gateways,id',
            'session_ttl_seconds'    => 'integer|min:3600|max:86400',
            'max_sessions_per_agent' => 'integer|min:1|max:5',
        ]);

        // Validate gateway is accessible by this tenant
        if (!empty($validated['voice_gateway_id'])) {
            $gateway = VoiceGateway::find($validated['voice_gateway_id']);
            if (!$gateway || !$gateway->isAccessibleByTenant($tenant->id)) {
                return back()->with('error', 'Selected gateway is not available for this tenant.');
            }
        }

        $voiceEnabled = $request->boolean('voice_enabled', false);

        $setting = VoiceTenantSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'voice_enabled'          => $voiceEnabled,
                'preview_dialer_enabled' => $request->boolean('preview_dialer_enabled', false),
                'default_caller_id'      => $validated['default_caller_id'] ?? null,
                'voice_gateway_id'       => $validated['voice_gateway_id'] ?? null,
                'session_ttl_seconds'    => $validated['session_ttl_seconds'] ?? 28800,
                'max_sessions_per_agent' => $validated['max_sessions_per_agent'] ?? 1,
                // Persist deterministic context names for compatibility
                'dialplan_context'       => "xedik_t{$tenant->id}_agents",
                'outbound_context'       => "xedik_t{$tenant->id}_out",
            ]
        );

        return back()->with('success', "Voice settings for \"{$tenant->name}\" updated.");
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
