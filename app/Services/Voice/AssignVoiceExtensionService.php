<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Exceptions\VoiceConfigException;
use App\Models\VoiceExtension;
use App\Models\VoiceExtensionRange;
use App\Models\VoiceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssignVoiceExtensionService
{
    public function __construct(
        private readonly StableSipCredentialService $credentialService,
        private readonly AsteriskRealtimeService    $asteriskRealtime,
    ) {}

    /**
     * Find first free extension in tenant ranges and create a VoiceExtension record.
     * Idempotent: if user already has an active extension, returns it without creating a duplicate.
     *
     * Provisioning is synchronous — callers invoke provisionSync() after this method returns.
     */
    public function assignAutomatically(User $user): VoiceExtension
    {
        $this->assertTenantHasVoice($user);

        return DB::transaction(function () use ($user): VoiceExtension {
            $existing = $this->findExisting($user);
            if ($existing) {
                return $existing;
            }

            // Lock all active ranges to serialise concurrent assignments
            $ranges = VoiceExtensionRange::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->orderBy('range_start')
                ->lockForUpdate()
                ->get();

            if ($ranges->isEmpty()) {
                throw new VoiceConfigException(
                    'No active extension ranges configured for this tenant. Add a range before enabling voice.'
                );
            }

            $used = VoiceExtension::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->pluck('extension')
                ->map(fn($e) => (int) $e)
                ->all();

            $free = null;
            foreach ($ranges as $range) {
                for ($n = $range->range_start; $n <= $range->range_end; $n++) {
                    if (!in_array($n, $used, true)) {
                        $free = (string) $n;
                        break 2;
                    }
                }
            }

            if ($free === null) {
                throw new VoiceConfigException(
                    'All extensions in the configured ranges are taken. Add a new range or free an existing extension.'
                );
            }

            return $this->createExtensionRecord($user, $free);
        });
    }

    /**
     * Assign a specific extension number to the user.
     * Validates the number is within an active range and not already taken.
     */
    public function assignManually(User $user, string $extension): VoiceExtension
    {
        $this->assertTenantHasVoice($user);

        $extNum = (int) $extension;

        if ($extNum < 1) {
            throw new VoiceConfigException("Extension '{$extension}' is not a valid number.");
        }

        return DB::transaction(function () use ($user, $extension, $extNum): VoiceExtension {
            $existing = $this->findExisting($user);
            if ($existing && $existing->extension === $extension) {
                return $existing;
            }
            if ($existing) {
                throw new VoiceConfigException(
                    "User already has extension {$existing->extension}. Deactivate it first."
                );
            }

            $inRange = VoiceExtensionRange::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->where('range_start', '<=', $extNum)
                ->where('range_end', '>=', $extNum)
                ->lockForUpdate()
                ->exists();

            if (!$inRange) {
                throw new VoiceConfigException(
                    "Extension {$extension} is not within any active range for this tenant."
                );
            }

            $taken = VoiceExtension::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('extension', $extension)
                ->exists();

            if ($taken) {
                throw new VoiceConfigException(
                    "Extension {$extension} is already assigned to another user."
                );
            }

            return $this->createExtensionRecord($user, $extension);
        });
    }

    /**
     * Deactivate voice for a user: marks extension inactive and invalidates
     * SIP credentials in Asterisk.  Does NOT delete the extension record.
     */
    public function deactivate(User $user): void
    {
        $extension = VoiceExtension::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$extension) {
            return;
        }

        $extension->update(['is_active' => false]);

        try {
            $this->credentialService->invalidatePassword($extension);
        } catch (Throwable $e) {
            Log::warning(
                "AssignVoiceExtensionService: could not invalidate SIP password for extension {$extension->id}",
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Reactivate a previously deactivated extension and re-provision it.
     */
    public function reactivate(User $user): VoiceExtension
    {
        $extension = VoiceExtension::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('is_active', false)
            ->latest('updated_at')
            ->first();

        if (!$extension) {
            throw new VoiceConfigException('No inactive extension found for this user to reactivate.');
        }

        $this->assertTenantHasVoice($user);

        $extension->update([
            'is_active'               => true,
            'sip_provisioning_status' => 'pending',
            'sip_provisioning_error'  => null,
        ]);

        // Caller invokes provisionSync() after this returns to push the extension
        // to Asterisk Realtime synchronously (UserController / VoiceExtensionAdminController).

        return $extension->fresh();
    }

    /**
     * Full Voice cleanup before a user is permanently deleted.
     *
     * Sequence:
     *   1. Revoke all active VoiceSessions → client heartbeat gets 409 on next tick
     *   2. Invalidate SIP credentials in Asterisk → prevents new REGISTER
     *   3. Delete ps_endpoints/auths/aors from Asterisk Realtime
     *   4. Release the VoiceExtension (user_id=null, is_active=false)
     *
     * If Asterisk is unavailable, the extension is marked 'deprovision_failed'
     * and its user_id is still set to null so the user deletion can proceed.
     * An admin can retry cleanup from the Extensions panel.
     *
     * Does NOT delete the VoiceExtension row — the record is preserved for audit.
     * The extension number is treated as "occupied" by AssignVoiceExtensionService
     * until an admin explicitly frees it.
     */
    public function releaseForDeletion(User $user): void
    {
        $extension = VoiceExtension::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();

        if (!$extension) {
            return; // User had no voice extension — nothing to clean up
        }

        // 1. Bulk-revoke active sessions so the UA discovers loss of access
        //    on the next heartbeat (409 inactive_session).
        VoiceSession::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'registered', 'active'])
            ->update([
                'status'         => 'revoked',
                'revoked_at'     => now(),
                'failure_reason' => 'user_deleted',
            ]);

        // 2. Invalidate the SIP password in Asterisk so the UA cannot re-register.
        if (filled($extension->pjsip_auth)) {
            try {
                $this->credentialService->invalidatePassword($extension->fresh());
            } catch (Throwable $e) {
                Log::warning('Voice: could not invalidate SIP credentials during user deletion', [
                    'extension_id' => $extension->id,
                    'error_class'  => get_class($e),
                    'message'      => Str::limit($e->getMessage(), 300),
                ]);
            }
        }

        // 3. Delete endpoint/auth/aor rows from Asterisk Realtime.
        $endpointId = $extension->pjsip_endpoint;
        $asteriskOk = false;

        if (filled($endpointId)) {
            try {
                $this->asteriskRealtime->deprovisionEndpoint($endpointId);
                $asteriskOk = true;
            } catch (Throwable $e) {
                Log::warning('Voice: Asterisk deprovisioning failed during user deletion', [
                    'extension_id' => $extension->id,
                    'tenant_id'    => $extension->tenant_id,
                    'endpoint_id'  => $endpointId,
                    'error_class'  => get_class($e),
                    'message'      => Str::limit($e->getMessage(), 300),
                ]);
            }
        } else {
            // No endpoint was ever provisioned in Asterisk
            $asteriskOk = true;
        }

        // 4. Release the extension record.
        //    user_id=null → user row can be deleted without triggering the cascade,
        //    because after this save() no row points to the user being deleted.
        if ($asteriskOk) {
            // Asterisk rows were removed — safe to clear all local references.
            $extension->forceFill([
                'user_id'                 => null,
                'is_active'               => false,
                'sip_provisioning_status' => 'released',
                'sip_provisioning_error'  => null,
                'released_at'             => now(),
                'sip_username'            => null,
                'pjsip_endpoint'          => null,
                'pjsip_auth'              => null,
                'pjsip_aor'               => null,
                'sip_invalidated_at'      => now(),
            ])->save();
        } else {
            // Asterisk rows may still exist (Asterisk was unavailable).
            // PRESERVE pjsip_endpoint / pjsip_auth / pjsip_aor / sip_username
            // so that Retry Deprovisioning can locate and remove them later.
            // The endpoint ID is deterministic (v{tid}_u{uid}_{ext}) but storing
            // it explicitly is safer and required for the retry mechanism.
            $extension->forceFill([
                'user_id'                 => null,
                'is_active'               => false,
                'sip_provisioning_status' => 'deprovision_failed',
                'released_at'             => now(),
                // pjsip_endpoint / pjsip_auth / pjsip_aor / sip_username: intentionally kept
            ])->save();
        }
    }

    private function assertTenantHasVoice(User $user): void
    {
        if (!$user->tenant_id) {
            throw new VoiceConfigException('This user has no tenant assigned.');
        }

        $setting = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$setting?->voice_enabled) {
            throw new VoiceConfigException('Voice is not enabled for this tenant.');
        }
    }

    private function findExisting(User $user): ?VoiceExtension
    {
        return VoiceExtension::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->first();
    }

    private function createExtensionRecord(User $user, string $extension): VoiceExtension
    {
        $tenantSetting = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        return VoiceExtension::withoutGlobalScopes()->create([
            'tenant_id'               => $user->tenant_id,
            'user_id'                 => $user->id,
            'extension'               => $extension,
            'display_name'            => $user->name,
            'dialplan_context'        => "xedik_t{$user->tenant_id}_agents",
            'outbound_context'        => "xedik_t{$user->tenant_id}_out",
            'caller_id_number'        => $tenantSetting?->default_caller_id,
            'caller_id_name'          => $user->name,
            'caller_id_source'        => 'tenant',
            'allow_outbound'          => true,
            'allow_internal'          => true,
            'allow_transfer'          => true,
            'is_active'               => true,
            'sip_provisioning_status' => 'pending',
        ]);
    }
}
