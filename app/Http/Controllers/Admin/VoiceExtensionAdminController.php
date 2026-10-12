<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceExtension;
use App\Services\OmniQueue\OmniQueueAsteriskMemberSyncService;
use App\Services\Voice\AsteriskRealtimeService;
use App\Services\Voice\ProvisionVoiceExtensionService;
use App\Services\Voice\StableSipCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VoiceExtensionAdminController extends Controller
{
    /** Statuses that indicate the endpoint still exists (or may exist) in Asterisk. */
    private const ASTERISK_LIVE_STATUSES = ['pending', 'provisioned', 'failed'];

    /** Statuses that mean the extension was released from a user (user_id = null). */
    private const RELEASED_STATUSES = ['released', 'deprovision_failed'];

    public function __construct(
        private readonly StableSipCredentialService     $credentials,
        private readonly ProvisionVoiceExtensionService $voiceProvisioner,
        private readonly AsteriskRealtimeService        $asteriskRealtime,
        private readonly OmniQueueAsteriskMemberSyncService $asteriskMemberSync,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->authorizeSuperAdmin();

        $query = VoiceExtension::withoutGlobalScopes()
            ->with(['tenant', 'user'])
            ->orderBy('tenant_id')
            ->orderBy('extension');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->tenant_id);
        }
        if ($request->filled('status')) {
            $query->where('sip_provisioning_status', $request->status);
        }

        $extensions = $query->get();
        $tenants    = Tenant::withoutGlobalScopes()->orderBy('name')->get();

        return view('admin.voice.extensions.index', compact('extensions', 'tenants'));
    }

    // ── Retry Provisioning (failed / pending) ─────────────────────────────────

    public function reprovision(VoiceExtension $voiceExtension): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Backend guard — do not trust the UI alone
        if (!$voiceExtension->is_active) {
            return back()->with('error', 'Cannot re-provision an inactive extension.');
        }

        if (in_array($voiceExtension->sip_provisioning_status, self::RELEASED_STATUSES, true)) {
            return back()->with('error',
                "Extension {$voiceExtension->extension} has status '{$voiceExtension->sip_provisioning_status}'. "
                . "Use Retry Deprovisioning to complete cleanup, not Retry Provisioning."
            );
        }

        $voiceExtension->update([
            'sip_provisioning_status' => 'pending',
            'sip_provisioning_error'  => null,
        ]);

        $provisioned = $this->provisionSync($voiceExtension->id);
        $this->syncStaticVoiceMembership($voiceExtension->fresh());

        return $provisioned
            ? back()->with('success', "Extension {$voiceExtension->extension} re-provisioned successfully.")
            : back()->with('error', "Re-provisioning failed for extension {$voiceExtension->extension}. Check the error details in the table.");
    }

    // ── Retry Deprovisioning (deprovision_failed) ─────────────────────────────

    public function retryDeprovision(VoiceExtension $voiceExtension): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Backend state guard — not just UI
        if ($voiceExtension->sip_provisioning_status !== 'deprovision_failed') {
            return back()->with('error',
                "Extension {$voiceExtension->extension} is not in deprovision_failed state "
                . "(current: {$voiceExtension->sip_provisioning_status}). "
                . "Only deprovision_failed extensions can use this action."
            );
        }

        $endpointId = $voiceExtension->pjsip_endpoint;

        // If no endpoint ID is stored, mark released immediately (nothing to delete in Asterisk)
        if (!$endpointId) {
            $voiceExtension->forceFill([
                'sip_provisioning_status' => 'released',
                'sip_provisioning_error'  => null,
                'sip_invalidated_at'      => now(),
            ])->save();
            return back()->with('success',
                "Extension {$voiceExtension->extension} marked as released (no Asterisk endpoint ID on record)."
            );
        }

        try {
            $this->asteriskRealtime->deprovisionEndpoint($endpointId);

            // Asterisk confirmed deletion — now safe to clear local references
            $voiceExtension->forceFill([
                'sip_provisioning_status' => 'released',
                'sip_provisioning_error'  => null,
                'sip_username'            => null,
                'pjsip_endpoint'          => null,
                'pjsip_auth'              => null,
                'pjsip_aor'               => null,
                'sip_invalidated_at'      => now(),
            ])->save();

            Log::info('Voice admin: retry-deprovision succeeded', [
                'extension_id' => $voiceExtension->id,
                'endpoint_id'  => $endpointId,
            ]);

            return back()->with('success',
                "Extension {$voiceExtension->extension} deprovisioned from Asterisk and marked as released."
            );
        } catch (\Throwable $e) {
            // IDs are preserved for the next retry attempt
            $voiceExtension->forceFill([
                'sip_provisioning_status' => 'deprovision_failed',
                'sip_provisioning_error'  => Str::limit($e->getMessage(), 500),
            ])->save();

            Log::warning('Voice admin: retry-deprovision failed', [
                'extension_id' => $voiceExtension->id,
                'endpoint_id'  => $endpointId,
                'error_class'  => get_class($e),
                'message'      => Str::limit($e->getMessage(), 300),
            ]);

            return back()->with('error',
                "Retry deprovisioning failed for extension {$voiceExtension->extension}. "
                . "Asterisk may still be unavailable. The endpoint IDs are preserved for the next attempt."
            );
        }
    }

    // ── Toggle Active (activate / deactivate) ─────────────────────────────────

    public function toggleActive(VoiceExtension $voiceExtension): RedirectResponse
    {
        $this->authorizeSuperAdmin();

        // Released and deprovision_failed extensions must not be "activated" —
        // they have no user and their Asterisk state is unknown/dirty.
        if (in_array($voiceExtension->sip_provisioning_status, self::RELEASED_STATUSES, true)) {
            return back()->with('error',
                "Extension {$voiceExtension->extension} has status '{$voiceExtension->sip_provisioning_status}' "
                . "and cannot be activated. Use Retry Deprovisioning to complete cleanup first."
            );
        }

        $wasActive = $voiceExtension->is_active;
        $voiceExtension->update(['is_active' => !$wasActive]);

        if ($wasActive) {
            // Deactivating — invalidate SIP credentials silently
            try {
                $this->credentials->invalidatePassword($voiceExtension->fresh());
            } catch (\Throwable) {
                // Extension is already inactive; log suppressed intentionally
            }
            $this->syncStaticVoiceMembership($voiceExtension->fresh());
            return back()->with('success', "Extension {$voiceExtension->extension} deactivated.");
        }

        // Reactivating — provision synchronously
        $voiceExtension->update([
            'sip_provisioning_status' => 'pending',
            'sip_provisioning_error'  => null,
        ]);

        $provisioned = $this->provisionSync($voiceExtension->id);
        $this->syncStaticVoiceMembership($voiceExtension->fresh());

        return $provisioned
            ? back()->with('success', "Extension {$voiceExtension->extension} activated and provisioned.")
            : back()->with('error',
                "Extension {$voiceExtension->extension} activated but provisioning failed. "
                . "Check the error details in the table and use Retry when Asterisk is available."
            );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function provisionSync(int $extensionId): bool
    {
        try {
            $this->voiceProvisioner->provision($extensionId);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Voice admin re-provisioning failed', [
                'extension_id' => $extensionId,
                'error_class'  => get_class($e),
                'message'      => Str::limit($e->getMessage(), 500),
            ]);
            return false;
        }
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }

    private function syncStaticVoiceMembership(?VoiceExtension $extension): void
    {
        $user = $extension?->user;

        if ($user) {
            $this->asteriskMemberSync->syncUserQueues($user);
        }
    }
}
