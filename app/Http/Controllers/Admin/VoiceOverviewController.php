<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceExtension;
use App\Services\Voice\AsteriskRealtimeService;

class VoiceOverviewController extends Controller
{
    public function __construct(private readonly AsteriskRealtimeService $asterisk) {}

    public function index()
    {
        $this->authorizeSuperAdmin();

        $asteriskHealth = $this->asterisk->health();

        $tenants = Tenant::with('voiceTenantSetting')
            ->withoutGlobalScopes()
            ->orderBy('name')
            ->get();

        $tenantsWithVoice = $tenants->filter(fn($t) => $t->voiceTenantSetting?->voice_enabled)->values();

        $extensionStats = VoiceExtension::withoutGlobalScopes()
            ->selectRaw('sip_provisioning_status, count(*) as total')
            ->groupBy('sip_provisioning_status')
            ->pluck('total', 'sip_provisioning_status');

        return view('admin.voice.overview', [
            'asteriskHealth'   => $asteriskHealth,
            'tenants'          => $tenants,
            'tenantsWithVoice' => $tenantsWithVoice,
            'extensionStats'   => $extensionStats,
        ]);
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
