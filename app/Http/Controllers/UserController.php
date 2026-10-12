<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\VoiceConfigException;
use App\Models\User;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\VoiceCallerId;
use App\Models\VoiceExtension;
use App\Models\VoiceGateway;
use App\Services\OmniQueue\OmniQueueAsteriskMemberSyncService;
use App\Services\Voice\AsteriskRealtimeService;
use App\Services\Voice\AssignVoiceExtensionService;
use App\Services\Voice\ProvisionVoiceExtensionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(
        private readonly AssignVoiceExtensionService     $voiceAssign,
        private readonly ProvisionVoiceExtensionService  $voiceProvisioner,
        private readonly AsteriskRealtimeService         $asteriskRealtime,
        private readonly OmniQueueAsteriskMemberSyncService $asteriskMemberSync,
    ) {}

    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();

        $query = User::with('tenant')->withCount(['leads', 'calls'])->latest();
        if (!$isSuperAdmin) {
            $query->where('tenant_id', auth()->user()->tenant_id);
        }

        $users = $query->get();

        return view('users.index', compact('users', 'isSuperAdmin'));
    }

    public function create()
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        return view('users.create', $this->formData());
    }

    public function edit(User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);
        $this->authorizeTenantAccess($user);

        $user->load('voiceExtension');

        return view('users.edit', array_merge($this->formData(), compact('user')));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $tenantId     = $isSuperAdmin ? (int) $request->tenant_id : auth()->user()->tenant_id;

        $rules = [
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|unique:users,email',
            'password'              => ['required', 'confirmed', Password::default()],
            'role'                  => ['required', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'phone_user_id'         => 'nullable|string|max:50|unique:users,phone_user_id',
            'enable_voice'          => 'nullable|boolean',
            'voice_assignment'      => 'nullable|in:automatic,manual',
            'voice_extension'       => 'nullable|string|max:32',
            'personal_caller_id_id' => 'nullable|integer|exists:voice_caller_ids,id',
        ];
        if ($isSuperAdmin) {
            $rules['tenant_id'] = 'required|exists:tenants,id';
        }

        $validated = $request->validate($rules, [
            'email.unique'         => 'This email is already registered. Each user must have a unique email across all tenants.',
            'phone_user_id.unique' => 'This Phone User ID is already assigned to another user.',
        ]);

        $enableVoice        = $request->boolean('enable_voice', false);
        $voiceMode          = $validated['voice_assignment'] ?? 'automatic';
        $manualExt          = trim($validated['voice_extension'] ?? '');
        $personalCallerIdId = isset($validated['personal_caller_id_id']) ? (int) $validated['personal_caller_id_id'] : null;
        $voiceExtension     = null;
        $createdUser        = null;

        // Validate that the selected caller ID belongs to this tenant (server-side guard)
        if ($personalCallerIdId !== null) {
            $callerIdOk = VoiceCallerId::withoutGlobalScopes()
                ->where('id', $personalCallerIdId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('is_verified', true)
                ->exists();
            if (!$callerIdOk) {
                return back()->withInput()->withErrors(['personal_caller_id_id' => 'Invalid Caller ID selection.']);
            }
        }

        try {
            DB::transaction(function () use ($validated, $tenantId, $enableVoice, $voiceMode, $manualExt, $personalCallerIdId, &$voiceExtension, &$createdUser) {
                $user = User::create([
                    'tenant_id'     => $tenantId,
                    'name'          => $validated['name'],
                    'email'         => $validated['email'],
                    'password'      => Hash::make($validated['password']),
                    'role_id'       => (int) $validated['role'],
                    'phone_user_id' => $validated['phone_user_id'] ?? null,
                    'is_active'     => true,
                ]);

                if ($enableVoice) {
                    $voiceExtension = ($voiceMode === 'manual' && $manualExt !== '')
                        ? $this->voiceAssign->assignManually($user, $manualExt)
                        : $this->voiceAssign->assignAutomatically($user);

                    if ($voiceExtension && $personalCallerIdId !== null) {
                        $this->applyPersonalCallerId($voiceExtension, $personalCallerIdId);
                    }
                }

                $createdUser = $user;
            });
        } catch (VoiceConfigException $e) {
            return back()->withInput()->withErrors(['enable_voice' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['enable_voice' => 'Extension already taken by another user. Please try again.']);
        }

        // Provisioning runs synchronously AFTER the transaction commits.
        // A failure (Asterisk down) does NOT roll back the user — the extension
        // is marked 'failed' and the admin can retry from the Voice Extensions panel.
        $provisioningOk = true;
        if ($voiceExtension?->id) {
            $provisioningOk = $this->provisionSync($voiceExtension->id);
            $this->syncStaticVoiceMembership($createdUser?->fresh());
        }

        return redirect()->route('users.index')->with(
            'success',
            $provisioningOk
                ? 'User created successfully.'
                : 'User created successfully. Voice provisioning could not be completed — retry from the Extensions panel when Asterisk is available.'
        );
    }

    public function update(Request $request, User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);
        $this->authorizeTenantAccess($user);

        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $tenantId     = $isSuperAdmin ? (int) $request->tenant_id : auth()->user()->tenant_id;

        if ($user->isSuperAdmin() && $request->role !== 'admin') {
            return back()
                ->withInput()
                ->with('edit_user_id', $user->id)
                ->with('error', 'Cannot change the superadmin role.');
        }

        $rules = [
            'name'                  => 'required|string|max:100',
            'email'                 => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role'                  => ['required', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
            'phone_user_id'         => ['nullable', 'string', 'max:50', Rule::unique('users', 'phone_user_id')->ignore($user->id)],
            'password'              => ['nullable', 'confirmed', Password::default()],
            'enable_voice'          => 'nullable|boolean',
            'voice_assignment'      => 'nullable|in:automatic,manual',
            'voice_extension'       => 'nullable|string|max:32',
            'personal_caller_id_id' => 'nullable|integer|exists:voice_caller_ids,id',
        ];
        if ($isSuperAdmin) {
            $rules['tenant_id'] = 'required|exists:tenants,id';
        }

        $validated = $request->validate($rules);

        $personalCallerIdId = isset($validated['personal_caller_id_id']) ? (int) $validated['personal_caller_id_id'] : null;

        // Server-side guard: caller ID must belong to the user's tenant
        if ($personalCallerIdId !== null) {
            $callerIdOk = VoiceCallerId::withoutGlobalScopes()
                ->where('id', $personalCallerIdId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('is_verified', true)
                ->exists();
            if (!$callerIdOk) {
                return back()
                    ->withInput()
                    ->with('edit_user_id', $user->id)
                    ->withErrors(['personal_caller_id_id' => 'Invalid Caller ID selection.']);
            }
        }

        $updateData = [
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'role_id'       => (int) $validated['role'],
            'phone_user_id' => $validated['phone_user_id'] ?? null,
        ];
        if ($isSuperAdmin) {
            $updateData['tenant_id'] = $tenantId;
        }
        if (!empty($validated['password'])) {
            $updateData['password']              = Hash::make($validated['password']);
            $updateData['failed_login_attempts'] = 0;
            $updateData['locked_at']             = null;
        }

        // Determine current voice state
        $currentlyActive = $user->hasActiveVoice();
        $wantsVoice      = $request->boolean('enable_voice', $currentlyActive);
        $voiceMode       = $validated['voice_assignment'] ?? 'automatic';
        $manualExt       = trim($validated['voice_extension'] ?? '');
        $newExtension    = null;
        // Tracks an already-provisioned extension whose callerid changed and needs
        // a lightweight Asterisk sync (updateEndpointCallerId) after the DB transaction.
        $extForCallerIdSync = null;
        $voiceReadinessChanged = false;

        try {
            DB::transaction(function () use ($user, $updateData, $wantsVoice, $currentlyActive, $voiceMode, $manualExt, $personalCallerIdId, &$newExtension, &$extForCallerIdSync, &$voiceReadinessChanged) {
                $user->update($updateData);

                if ($wantsVoice && !$currentlyActive) {
                    // Enabling voice: check if a deactivated extension exists (reactivate) or assign new
                    $inactiveExt = VoiceExtension::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->where('is_active', false)
                        ->latest('updated_at')
                        ->first();

                    if ($inactiveExt) {
                        $newExtension = $this->voiceAssign->reactivate($user->fresh());
                    } else {
                        $newExtension = ($voiceMode === 'manual' && $manualExt !== '')
                            ? $this->voiceAssign->assignManually($user->fresh(), $manualExt)
                            : $this->voiceAssign->assignAutomatically($user->fresh());
                    }

                    if ($newExtension && $personalCallerIdId !== null) {
                        $this->applyPersonalCallerId($newExtension, $personalCallerIdId);
                    }
                    $voiceReadinessChanged = true;
                    // caller_id_number is picked up by provisionSync() → ensureStableEndpoint()
                } elseif (!$wantsVoice && $currentlyActive) {
                    // Disabling voice
                    $this->voiceAssign->deactivate($user->fresh());
                    $voiceReadinessChanged = true;
                } elseif ($wantsVoice && $currentlyActive) {
                    // Voice already active — update personal caller ID on existing extension
                    $ext = VoiceExtension::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->where('is_active', true)
                        ->first();
                    if ($ext) {
                        if ($personalCallerIdId !== null) {
                            $this->applyPersonalCallerId($ext, $personalCallerIdId);
                            $extForCallerIdSync = $ext->fresh();
                        } elseif ($ext->caller_id_source === 'user') {
                            // User cleared the personal caller ID — revert to tenant default
                            $this->clearPersonalCallerId($ext);
                            $extForCallerIdSync = $ext->fresh();
                        }
                    }
                }
            });
        } catch (VoiceConfigException $e) {
            return back()->withInput()->withErrors(['enable_voice' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['enable_voice' => 'Extension already taken. Please try again.']);
        }

        // Provisioning runs synchronously after the transaction commits.
        // New extensions need full provision; existing extensions with a Caller ID change
        // need only a lightweight callerid field update in ps_endpoints.
        $provisioningOk  = true;
        $callerIdSyncOk  = true;

        if ($newExtension && $newExtension->sip_provisioning_status === 'pending') {
            $provisioningOk = $this->provisionSync($newExtension->id);
        } elseif ($extForCallerIdSync) {
            $callerIdSyncOk = $this->syncCallerIdToAsterisk($extForCallerIdSync);
        }

        if ($voiceReadinessChanged) {
            $this->syncStaticVoiceMembership($user->fresh());
        }

        $message = match (true) {
            !$provisioningOk => 'User updated successfully. Voice provisioning could not be completed — retry from the Extensions panel when Asterisk is available.',
            !$callerIdSyncOk => 'User updated successfully. Caller ID saved, but could not be synchronized to Asterisk — it will take effect after the next Voice re-provision.',
            default          => 'User updated successfully.',
        };

        return redirect()->route('users.index')->with('success', $message);
    }

    public function destroy(User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'This user cannot be deleted.');
        }

        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        // Voice cleanup runs BEFORE $user->delete() so that:
        //   - SIP credentials are invalidated in Asterisk
        //   - Asterisk Realtime ps_endpoints/auths/aors are removed
        //   - VoiceExtension.user_id is set to null
        // With user_id=null, the cascadeOnDelete on the FK fires on no rows,
        // so the VoiceExtension record is PRESERVED for audit/history.
        // Any failure in Asterisk cleanup is logged and does not block deletion.
        $this->voiceAssign->releaseForDeletion($user);
        $this->syncStaticVoiceMembership($user->fresh());

        $user->delete();
        return back()->with('success', 'User deleted.');
    }

    public function toggleActive(User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        if ($user->isSuperAdmin()) {
            return response()->json(['success' => false, 'error' => 'Cannot deactivate superadmin.'], 403);
        }

        $user->update(['is_active' => !$user->is_active]);
        $this->syncStaticVoiceMembership($user->fresh());
        return response()->json(['success' => true, 'is_active' => $user->is_active]);
    }

    public function profileGet(): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        return response()->json([
            'name'      => $user->name,
            'signature' => $user->signature,
        ]);
    }

    public function unlock(User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_users'), 403);

        $user->update([
            'failed_login_attempts' => 0,
            'locked_at'             => null,
        ]);

        return redirect()->route('users.index')->with('success', "Account '{$user->name}' unlocked successfully.");
    }

    public function profileSave(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'signature' => 'nullable|string',
        ]);

        auth()->user()->update([
            'name'      => $request->name,
            'signature' => $request->signature,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Provision an extension synchronously.
     *
     * Returns true on success, false on failure.  Exceptions are caught so that
     * Asterisk being unavailable does NOT cause a 500 or roll back the user record.
     * VoiceExtensionProvisioningService already marks the extension as 'failed'
     * before re-throwing, so the admin can see the error in the Extensions panel.
     */
    private function provisionSync(int $extensionId): bool
    {
        try {
            $this->voiceProvisioner->provision($extensionId);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Voice provisioning failed after user save', [
                'extension_id' => $extensionId,
                'error_class'  => get_class($e),
                'message'      => Str::limit($e->getMessage(), 500),
            ]);
            return false;
        }
    }

    /**
     * Push the current caller_id_number to ps_endpoints.callerid in Asterisk.
     *
     * Called after applyPersonalCallerId() or clearPersonalCallerId() on an
     * already-provisioned extension.  Does NOT rotate credentials, touch ps_auths,
     * ps_aors, or pjsip_provisioned_at.
     *
     * If the extension has no pjsip_endpoint yet (not provisioned), returns true
     * silently — the next full provision will pick up caller_id_number.
     *
     * Returns true on success, false on any Asterisk error.
     */
    private function syncCallerIdToAsterisk(VoiceExtension $ext): bool
    {
        if (blank($ext->pjsip_endpoint)) {
            return true;
        }

        try {
            $this->asteriskRealtime->updateEndpointCallerId($ext);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Caller ID Asterisk sync failed after user update', [
                'extension_id'     => $ext->id,
                'pjsip_endpoint'   => $ext->pjsip_endpoint,
                'caller_id_number' => $ext->caller_id_number,
                'error_class'      => get_class($e),
                'message'          => Str::limit($e->getMessage(), 500),
            ]);
            return false;
        }
    }

    private function syncStaticVoiceMembership(?User $user): void
    {
        if (!$user) {
            return;
        }

        $this->asteriskMemberSync->syncUserQueues($user);
    }

    /**
     * Abort if a non-superadmin tries to access a user from another tenant.
     * Superadmin can always access any user.
     */
    private function authorizeTenantAccess(User $user): void
    {
        if (auth()->user()->isSuperAdmin()) {
            return;
        }
        abort_if((int) $user->tenant_id !== (int) auth()->user()->tenant_id, 403);
    }

    /**
     * Load all data needed by the create/edit form views.
     * Extracted so create() and edit() don't duplicate the query logic.
     */
    private function formData(): array
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        $tenants = $isSuperAdmin
            ? Tenant::withoutGlobalScopes()->orderBy('name')->get()
            : collect();

        $roles = $isSuperAdmin
            ? Role::withoutGlobalScopes()->orderBy('tenant_id')->orderBy('label')->get()
            : Role::orderBy('label')->get();

        $tenantVoiceEnabled       = false;
        $tenantVoiceMap           = [];
        $voiceCallerIds           = collect();
        $voiceCallerIdsMap        = [];
        $tenantDefaultCallerId    = null;
        $tenantDefaultCallerIdMap = [];

        if ($isSuperAdmin) {
            $settings = \App\Models\VoiceTenantSetting::withoutGlobalScopes()->get();

            $tenantVoiceMap = $settings
                ->pluck('voice_enabled', 'tenant_id')
                ->map(fn($v) => (bool) $v)
                ->all();

            $tenantDefaultCallerIdMap = $settings
                ->pluck('default_caller_id', 'tenant_id')
                ->all();

            $voiceCallerIdsMap = VoiceCallerId::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('is_verified', true)
                ->orderBy('number')
                ->get()
                ->groupBy('tenant_id')
                ->map(fn($cids) => $cids->map(fn($c) => [
                    'id'    => $c->id,
                    'number'=> $c->number,
                    'name'  => $c->name,
                    'label' => $c->display_label,
                ])->values())
                ->all();
        } elseif (auth()->user()->tenant_id) {
            $tenantId = auth()->user()->tenant_id;
            $setting  = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->first();

            $tenantVoiceEnabled    = (bool) ($setting?->voice_enabled ?? false);
            $tenantDefaultCallerId = $setting?->default_caller_id;

            if ($tenantVoiceEnabled) {
                $voiceCallerIds = VoiceCallerId::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->where('is_verified', true)
                    ->orderBy('number')
                    ->get();
            }
        }

        return compact(
            'roles', 'tenants', 'isSuperAdmin',
            'tenantVoiceEnabled', 'tenantVoiceMap',
            'voiceCallerIds', 'voiceCallerIdsMap',
            'tenantDefaultCallerId', 'tenantDefaultCallerIdMap'
        );
    }

    /**
     * Set a personal Caller ID on a VoiceExtension.
     *
     * Sets caller_id_source = 'user' so the tenant default can never overwrite it.
     * Syncs caller_id_number from the catalog record so Asterisk provisioning picks it up.
     */
    private function applyPersonalCallerId(VoiceExtension $ext, int $callerIdId): void
    {
        $cid = VoiceCallerId::withoutGlobalScopes()->find($callerIdId);
        if (!$cid) {
            return;
        }
        $ext->update([
            'personal_caller_id_id' => $cid->id,
            'caller_id_number'      => $cid->number,
            'caller_id_source'      => 'user',
        ]);
    }

    /**
     * Clear the personal Caller ID, restoring the tenant default.
     *
     * Sets caller_id_source = 'tenant' so future changes to the tenant default
     * will propagate to this extension again.
     */
    private function clearPersonalCallerId(VoiceExtension $ext): void
    {
        $tenantDefault = \App\Models\VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $ext->tenant_id)
            ->value('default_caller_id');

        $ext->update([
            'personal_caller_id_id' => null,
            'caller_id_number'      => $tenantDefault,
            'caller_id_source'      => 'tenant',
        ]);
    }
}
