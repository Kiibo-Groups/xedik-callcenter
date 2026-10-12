<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\AgentSession;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceExtension;
use App\Models\VoiceSession;
use App\Services\AgentStatusService;
use App\Services\Telephony\TelephonyCallWrapupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VoiceSessionService
{
    private const ACTIVE_STATUSES = [
        'pending',
        'registered',
        'active',
    ];

    private const RECONNECT_AGENT_ACTION_NONE = 'none';
    private const RECONNECT_AGENT_ACTION_WRAP_UP = 'wrap_up';
    private const RECONNECT_AGENT_ACTION_RESUME = 'resume';

    public function __construct(
        private readonly StableSipCredentialService   $credentials,
        private readonly VoiceOwnershipPolicyService  $ownershipPolicy,
        private readonly AsteriskQueueMemberAvailabilityService $queueMemberAvailability,
        private readonly VoiceConversationService $voiceConversations,
        private readonly AgentStatusService $agentStatus,
        private readonly TelephonyCallWrapupService $telephonyWrapup,
    ) {}

    public function create(
        User $user,
        string $browserTabId,
        ?string $ip = null,
        ?string $userAgent = null,
        ?VoicePerformanceMeasurement $performance = null
    ): array {
        $serviceStartedAt = hrtime(true);

        try {
            if (!config('voice.enabled')) {
                return [
                    'state' => 'disabled',
                    'session' => null,
                ];
            }

            $tenantSetting = $user->tenant?->voiceTenantSetting;

            if (!$tenantSetting || !$tenantSetting->voice_enabled) {
                return [
                    'state' => 'tenant_disabled',
                    'session' => null,
                ];
            }

            $result = VoicePerformanceMeasurement::capture(
                $performance,
                'transaction_total_ms',
                fn (): array => DB::transaction(function () use (
                    $user,
                    $browserTabId,
                    $ip,
                    $userAgent,
                    $tenantSetting,
                    $performance
                ): array {
                    $existing = VoicePerformanceMeasurement::capture(
                        $performance,
                        'session_lookup_ms',
                        fn () => VoiceSession::withoutGlobalScopes()
                            ->where('tenant_id', $user->tenant_id)
                            ->where('user_id', $user->id)
                            ->whereIn('status', self::ACTIVE_STATUSES)
                            ->orderByDesc('last_seen_at')
                            ->first()
                    );

                    if ($existing) {
                        return [
                            'state' => 'conflict',
                            'session' => $existing,
                        ];
                    }

                    $extension = VoicePerformanceMeasurement::capture(
                        $performance,
                        'extension_lookup_ms',
                        fn () => VoiceExtension::withoutGlobalScopes()
                            ->where('tenant_id', $user->tenant_id)
                            ->where('user_id', $user->id)
                            ->where('is_active', true)
                            ->first()
                    );

                    if (!$extension) {
                        return [
                            'state' => 'no_extension',
                            'session' => null,
                        ];
                    }

                    if ($extension->sip_provisioning_status
                        !== 'provisioned') {
                        return [
                            'state' => 'extension_not_provisioned',
                            'session' => null,
                        ];
                    }

                    $ttl = (int) (
                        $tenantSetting->session_ttl_seconds
                        ?: config('voice.session_ttl_seconds', 28800)
                    );

                    $session = VoicePerformanceMeasurement::capture(
                        $performance,
                        'session_persist_ms',
                        fn () => VoiceSession::withoutGlobalScopes()
                            ->create([
                                'tenant_id'              => $user->tenant_id,
                                'user_id'                => $user->id,
                                'voice_extension_id'     => $extension->id,
                                'browser_tab_id'         => $browserTabId,
                                'session_uuid'           => (string) Str::uuid(),
                                'status'                 => 'pending',
                                'last_seen_at'           => now(),
                                'expires_at'             => now()->addSeconds($ttl),
                                'ip'                     => $ip,
                                'user_agent'             => $userAgent,
                                // Explicit no_call for NEW sessions.
                                // Historical sessions with null are 'unknown'.
                                'call_state'             => VoiceSession::CALL_STATE_NO_CALL,
                                'call_state_updated_at'  => now(),
                            ])
                    );

                    $rotated = $this->credentials->rotatePassword(
                        $extension,
                        $performance
                    );

                    return [
                        'state' => 'created',
                        'session' => $session,
                        'extension' => $rotated['extension'],
                        'sip' => [
                            'uri' => sprintf(
                                'sip:%s@%s',
                                $rotated['extension']->sip_username,
                                config('voice.realm')
                            ),
                            'authorization_user' =>
                                $rotated['extension']->sip_username,
                            'password' => $rotated['password'],
                            'realm' => config('voice.realm'),
                            'wss_server' => config('voice.wss_server'),
                        ],
                    ];
                })
            );

            if (($result['state'] ?? null) === 'created') {
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'voice_session_created_fail_closed');
            }

            return $result;
        } finally {
            $performance?->recordSince(
                'service_total_ms',
                $serviceStartedAt
            );
        }
    }

    public function markRegistered(
        VoiceSession $session,
        string $browserTabId,
        ?VoicePerformanceMeasurement $performance = null
    ): VoiceSession {
        $serviceStartedAt = hrtime(true);

        try {
            VoicePerformanceMeasurement::capture(
                $performance,
                'registered_validation_ms',
                function () use ($session, $browserTabId): void {
                    $this->guardTab($session, $browserTabId);

                    if ($session->status !== 'pending') {
                        throw new RuntimeException(
                            'Only a pending voice session can be marked as registered.'
                        );
                    }
                }
            );

            $registered = VoicePerformanceMeasurement::capture(
                $performance,
                'registered_session_update_ms',
                function () use ($session): VoiceSession {
                    $session->forceFill([
                        'status' => 'registered',
                        'registered_at' => now(),
                        'last_seen_at' => now(),
                    ])->save();

                    return $session->fresh();
                }
            );

            if ($registered->user) {
                $this->queueMemberAvailability->pauseExistingMembershipsForVoiceSession($registered, 'voice_session_registered_fail_closed');
            }

            return $registered;
        } finally {
            $performance?->recordSince(
                'registered_service_total_ms',
                $serviceStartedAt
            );
        }
    }

    public function heartbeat(
        VoiceSession $session,
        string $browserTabId
    ): VoiceSession {
        $this->guardTab($session, $browserTabId);

        if (!in_array($session->status, self::ACTIVE_STATUSES, true)) {
            throw new RuntimeException(
                'The voice session is no longer active.'
            );
        }

        $tenantSetting = $session->tenant?->voiceTenantSetting;

        $ttl = (int) (
            $tenantSetting?->session_ttl_seconds
            ?: config('voice.session_ttl_seconds', 28800)
        );

        $updates = [
            'status'       => 'active',
            'last_seen_at' => now(),
            'expires_at'   => now()->addSeconds($ttl),
        ];

        // Refresh call_state_updated_at for active call states on every heartbeat.
        // This prevents a long call (> call_state_fresh_seconds) from having its
        // policy degrade from 'blocked' to 'confirm' due to staleness, as long as
        // the UA keeps sending heartbeats.
        //
        // For 'no_call' we intentionally do NOT refresh — the browser reports
        // no_call explicitly, and auto-refreshing it would misrepresent confidence.
        // For 'unknown' (null) we also skip — we cannot infer the call state.
        if (in_array($session->call_state, VoiceSession::ACTIVE_CALL_STATES, true)) {
            $updates['call_state_updated_at'] = now();
        }

        $session->forceFill($updates)->save();

        return $session->fresh();
    }

    /**
     * Update the declarative call state reported by the browser.
     *
     * Rules:
     *  - browser_tab_id must match (same tab that owns the session)
     *  - session must be in an active status
     *  - call_state must be an allowed value
     *  - call_started_at is set on first transition to an active call state
     *  - call_started_at is preserved (not cleared) when returning to no_call
     *  - idempotent: same state twice is accepted
     */
    public function updateCallState(
        VoiceSession $session,
        string $browserTabId,
        string $callState
    ): VoiceSession {
        $this->guardTab($session, $browserTabId);

        if (!in_array($session->status, self::ACTIVE_STATUSES, true)) {
            throw new RuntimeException(
                'Cannot update call state on a terminated or replaced session.'
            );
        }

        $updates = [
            'call_state'            => $callState,
            'call_state_updated_at' => now(),
        ];

        // Set call_started_at only when entering an active call for the first time
        if (
            in_array($callState, VoiceSession::ACTIVE_CALL_STATES, true)
            && !$session->call_started_at
        ) {
            $updates['call_started_at'] = now();
        }

        // Transitioning to no_call: call_started_at is intentionally PRESERVED
        // for audit of the previous call's duration. It is NOT cleared.

        $session->forceFill($updates)->save();

        return $session->fresh();
    }

    public function findActiveForUser(User $user): ?VoiceSession
    {
        return VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function ownership(
        User $user,
        string $browserTabId
    ): array {
        $session = $this->findActiveForUser($user);
        $eval    = $this->ownershipPolicy->evaluate($session, $browserTabId);

        return [
            // ── Backward-compatible fields ─────────────────────────────────
            'state'             => $eval['ownership'],        // none|same_tab|other_tab
            'takeover_required' => $eval['ownership'] === 'other_tab',
            'session'           => $eval['session'],

            // ── New policy fields (additive) ───────────────────────────────
            'ownership'          => $eval['ownership'],
            'policy'             => $eval['policy'],
            'reason'             => $eval['reason'],
            'call_state'         => $eval['call_state'],
            'call_state_is_fresh'=> $eval['call_state_is_fresh'],
            'agent_status'       => $eval['agent_status'],
        ];
    }

    public function reconnect(
        User $user,
        string $sessionUuid,
        string $browserTabId,
        ?VoicePerformanceMeasurement $performance = null
    ): array {
        $serviceStartedAt = hrtime(true);
        $cacheLockLifecycleStartedAt = null;
        $transactionReturnedAt = null;

        $performance?->recordDuration(
            'service_entry_ms',
            (hrtime(true) - $serviceStartedAt) / 1_000_000
        );

        try {
            if (!config('voice.enabled')) {
                return [
                    'state' => 'disabled',
                    'session' => null,
                ];
            }

            $performance?->recordDuration(
                'before_cache_lock_ms',
                (hrtime(true) - $serviceStartedAt) / 1_000_000
            );

            $lock = Cache::lock(sprintf(
                'voice-session-reconnect:%s:%s',
                $user->tenant_id,
                $user->id
            ), 30);
            $cacheLockLifecycleStartedAt = hrtime(true);

            $lockAcquired = VoicePerformanceMeasurement::capture(
                $performance,
                'cache_lock_wait_ms',
                fn (): bool => $lock->get()
            );

            if (!$lockAcquired) {
                return [
                    'state' => 'race',
                    'session' => null,
                    'message' =>
                        'Another voice reconnect is already in progress.',
                ];
            }

            try {
                $beforeTransactionStartedAt = hrtime(true);
                $performance?->recordDuration(
                    'before_transaction_ms',
                    ($beforeTransactionStartedAt
                        - $cacheLockLifecycleStartedAt) / 1_000_000
                );

                $transactionCallStartedAt = hrtime(true);
                $transactionBodyStartedAt = null;
                $transactionBodyFinishedAt = null;
                $transactionSucceeded = false;
                $postCommitAgentTransition = null;

                try {
                    $result = VoicePerformanceMeasurement::capture(
                        $performance,
                        'transaction_total_ms',
                        function () use (
                            $user,
                            $sessionUuid,
                            $browserTabId,
                            $performance,
                            &$transactionBodyStartedAt,
                            &$transactionBodyFinishedAt,
                            &$postCommitAgentTransition
                        ): array {
                            return DB::transaction(function () use (
                                $user,
                                $sessionUuid,
                                $browserTabId,
                                $performance,
                                &$transactionBodyStartedAt,
                                &$transactionBodyFinishedAt,
                                &$postCommitAgentTransition
                            ): array {
                        $transactionBodyStartedAt = hrtime(true);

                        try {
                            $sessionQuery =
                                VoicePerformanceMeasurement::capture(
                                    $performance,
                                    'session_query_ms',
                                    fn () => VoiceSession::withoutGlobalScopes()
                                ->where('session_uuid', $sessionUuid)
                                ->where('tenant_id', $user->tenant_id)
                                ->where('user_id', $user->id)
                                ->lockForUpdate()
                                );

                            $voiceSessionLookupStartedAt = hrtime(true);
                            $session = VoicePerformanceMeasurement::capture(
                                $performance,
                                'session_lock_ms',
                                fn () => $sessionQuery->first()
                            );
                            $performance?->recordDuration(
                                'voice_session_lookup_ms',
                                (hrtime(true) - $voiceSessionLookupStartedAt)
                                    / 1_000_000
                            );

                            if (!$session) {
                                return [
                                    'state' => 'not_found',
                                    'session' => null,
                                ];
                            }

                        $tenantSetting =
                            VoicePerformanceMeasurement::capture(
                                $performance,
                                'tenant_settings_lookup_ms',
                                fn () => $user->tenant
                                    ?->voiceTenantSetting
                            );

                        $eligibilityStartedAt = hrtime(true);
                        $validationResult =
                            VoicePerformanceMeasurement::capture(
                                $performance,
                                'validation_ms',
                                function () use (
                                    $session,
                                    $browserTabId,
                                    $tenantSetting
                                ): ?array {
                                    if (!$tenantSetting
                                        || !$tenantSetting->voice_enabled) {
                                        return [
                                            'state' => 'tenant_disabled',
                                            'session' => $session,
                                        ];
                                    }

                                    if (!hash_equals(
                                        (string) $session->browser_tab_id,
                                        $browserTabId
                                    )) {
                                        return [
                                            'state' => 'forbidden',
                                            'session' => $session,
                                            'message' =>
                                                'This browser tab does not own the voice session.',
                                        ];
                                    }

                                    if (!in_array(
                                        $session->status,
                                        self::ACTIVE_STATUSES,
                                        true
                                    )) {
                                        return [
                                            'state' => 'invalid_state',
                                            'session' => $session,
                                            'message' =>
                                                'The voice session cannot be reconnected.',
                                        ];
                                    }

                                    return null;
                                }
                            );
                        $performance?->recordDuration(
                            'eligibility_ms',
                            (hrtime(true) - $eligibilityStartedAt)
                                / 1_000_000
                        );

                        if ($validationResult) {
                            return $validationResult;
                        }

                        $currentSession =
                            VoicePerformanceMeasurement::capture(
                                $performance,
                                'current_session_lookup_ms',
                                fn () => VoiceSession::withoutGlobalScopes()
                                    ->where('tenant_id', $user->tenant_id)
                                    ->where('user_id', $user->id)
                                    ->whereIn(
                                        'status',
                                        self::ACTIVE_STATUSES
                                    )
                                    ->orderByDesc('last_seen_at')
                                    ->orderByDesc('created_at')
                                    ->orderByDesc('id')
                                    ->lockForUpdate()
                                    ->first()
                            );

                        $isCurrent = VoicePerformanceMeasurement::capture(
                            $performance,
                            'validation_ms',
                            fn (): bool => $currentSession
                                && (int) $currentSession->id
                                    === (int) $session->id
                        );

                        if (!$isCurrent) {
                            return [
                                'state' => 'not_current',
                                'session' => $session,
                                'message' =>
                                    'A newer voice session is already current.',
                            ];
                        }

                        $postCommitAgentTransition =
                            $this->terminalizeLinkedActiveCallsForReconnect(
                            $session,
                            $user
                        );

                            $extensionQuery =
                                VoicePerformanceMeasurement::capture(
                                    $performance,
                                    'extension_query_ms',
                                    fn () => VoiceExtension::withoutGlobalScopes()
                                        ->where(
                                            'id',
                                            $session->voice_extension_id
                                        )
                                        ->where(
                                            'tenant_id',
                                            $user->tenant_id
                                        )
                                        ->where('user_id', $user->id)
                                        ->where('is_active', true)
                                        ->lockForUpdate()
                                );

                            $voiceExtensionLookupStartedAt = hrtime(true);
                            $extension =
                                VoicePerformanceMeasurement::capture(
                                    $performance,
                                    'extension_lock_ms',
                                    fn () => $extensionQuery->first()
                                );
                            $performance?->recordDuration(
                                'voice_extension_lookup_ms',
                                (hrtime(true)
                                    - $voiceExtensionLookupStartedAt)
                                    / 1_000_000
                            );

                        $extensionEligibilityStartedAt = hrtime(true);
                        $extensionIsProvisioned =
                            VoicePerformanceMeasurement::capture(
                                $performance,
                                'validation_ms',
                                fn (): bool => $extension
                                    && $extension->sip_provisioning_status
                                        === 'provisioned'
                            );
                        $performance?->recordDuration(
                            'eligibility_ms',
                            (hrtime(true) - $extensionEligibilityStartedAt)
                                / 1_000_000
                        );

                        if (!$extensionIsProvisioned) {
                            return [
                                'state' => 'extension_not_provisioned',
                                'session' => $session,
                                'message' =>
                                    'The voice extension is not provisioned.',
                            ];
                        }

                            $ttl = (int) (
                                $tenantSetting->session_ttl_seconds
                                ?: config(
                                    'voice.session_ttl_seconds',
                                    28800
                                )
                            );

                            $beforeCredentialRotationAt = hrtime(true);
                            $performance?->recordDuration(
                                'before_credential_rotation_ms',
                                ($beforeCredentialRotationAt
                                    - $transactionBodyStartedAt)
                                    / 1_000_000
                            );

                            $rotated = $this->credentials->rotatePassword(
                                $extension,
                                $performance
                            );

                            $credentialRotationFinishedAt = hrtime(true);
                            $sessionUpdateStartedAt = hrtime(true);
                            $performance?->recordDuration(
                                'after_credential_rotation_ms',
                                ($sessionUpdateStartedAt
                                    - $credentialRotationFinishedAt)
                                    / 1_000_000
                            );

                        $updatedSession =
                            VoicePerformanceMeasurement::capture(
                                $performance,
                                'session_update_ms',
                                function () use ($session, $ttl) {
                                    $session->forceFill([
                                        'status' => 'pending',
                                        'registered_at' => null,
                                        'last_seen_at' => now(),
                                        'expires_at' =>
                                            now()->addSeconds($ttl),
                                        'revoked_at' => null,
                                        'replaced_at' => null,
                                        'failed_at' => null,
                                        'failure_reason' => null,
                                    ])->save();

                                    return $session->fresh();
                                }
                            );

                            return [
                                'state' => 'reconnecting',
                                'session' => $updatedSession,
                                'extension' => $rotated['extension'],
                                'sip' => [
                                    'uri' => sprintf(
                                        'sip:%s@%s',
                                        $rotated['extension']->sip_username,
                                        config('voice.realm')
                                    ),
                                    'authorization_user' =>
                                        $rotated['extension']->sip_username,
                                    'password' => $rotated['password'],
                                    'realm' => config('voice.realm'),
                                    'wss_server' =>
                                        config('voice.wss_server'),
                                ],
                            ];
                        } finally {
                            $transactionBodyFinishedAt = hrtime(true);
                            $performance?->recordDuration(
                                'transaction_body_ms',
                                ($transactionBodyFinishedAt
                                    - $transactionBodyStartedAt)
                                    / 1_000_000
                            );
                        }
                            });
                        }
                    );

                    $transactionSucceeded = true;

                    if (($result['state'] ?? null) === 'reconnecting') {
                        $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'voice_session_reconnect_fail_closed');
                        $this->applyReconnectPostCommitAgentTransition(
                            $user,
                            $postCommitAgentTransition
                        );
                    }

                    return $result;
                } finally {
                    $transactionReturnedAt = hrtime(true);

                    if ($transactionBodyStartedAt !== null) {
                        $performance?->recordDuration(
                            'transaction_begin_ms',
                            ($transactionBodyStartedAt
                                - $transactionCallStartedAt) / 1_000_000
                        );
                    }

                    if ($transactionBodyFinishedAt !== null) {
                        $performance?->recordDuration(
                            $transactionSucceeded
                                ? 'transaction_commit_ms'
                                : 'transaction_rollback_ms',
                            ($transactionReturnedAt
                                - $transactionBodyFinishedAt) / 1_000_000
                        );
                    }
                }
            } finally {
                $lock->release();

                if ($cacheLockLifecycleStartedAt !== null) {
                    $performance?->recordDuration(
                        'cache_lock_callback_total_ms',
                        (hrtime(true) - $cacheLockLifecycleStartedAt)
                            / 1_000_000
                    );
                }
            }
        } finally {
            if ($transactionReturnedAt !== null) {
                $performance?->recordDuration(
                    'after_transaction_ms',
                    (hrtime(true) - $transactionReturnedAt) / 1_000_000
                );
            }

            $performance?->recordSince(
                'service_total_ms',
                $serviceStartedAt
            );
            $performance?->recordSince(
                'service_exit_ms',
                $serviceStartedAt
            );
        }
    }
    
    /**
     * @param bool $confirmed  Whether the requesting tab explicitly confirmed
     *                         (required when policy=confirm in strict mode;
     *                         optional for backward compatibility until the
     *                         frontend is updated by Codex).
     */
    public function takeOver(
        User $user,
        string $browserTabId,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $confirmed = false
    ): array {
        if (!config('voice.enabled')) {
            return ['state' => 'disabled', 'session' => null];
        }

        $tenantSetting = $user->tenant?->voiceTenantSetting;

        if (!$tenantSetting || !$tenantSetting->voice_enabled) {
            return ['state' => 'tenant_disabled', 'session' => null];
        }

        $result = DB::transaction(function () use (
            $user,
            $browserTabId,
            $ip,
            $userAgent,
            $tenantSetting,
            $confirmed
        ): array {
            $extension = VoiceExtension::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (!$extension) {
                return ['state' => 'no_extension', 'session' => null];
            }

            if ($extension->sip_provisioning_status !== 'provisioned') {
                return ['state' => 'extension_not_provisioned', 'session' => null];
            }

            // Lock all active sessions to freeze call state during evaluation
            $activeSessions = VoiceSession::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->get();

            // ── Re-evaluate policy inside the transaction ─────────────────
            // The call_state may have changed since the frontend checked
            // ownership. We use the MOST RECENT active session as the
            // authoritative state (most likely the one being taken over).
            $existingSession = $activeSessions->sortByDesc('last_seen_at')->first();
            $policyResult    = $this->ownershipPolicy->evaluate($existingSession, $browserTabId);

            if ($policyResult['policy'] === 'blocked') {
                return [
                    'state'   => 'blocked',
                    'policy'  => 'blocked',
                    'reason'  => $policyResult['reason'],
                    'message' => 'The active voice session is currently handling a call.',
                    'session' => $existingSession,
                ];
            }

            // Strict confirmation guard — only active when call_state policy is enabled.
            //
            // Flag FALSE (default while frontend is unconnected):
            //   other_tab always resolves to 'confirm' with reason
            //   'call_state_policy_disabled', and we proceed without requiring
            //   confirmed=true to preserve backward compatibility.
            //
            // Flag TRUE (after Codex connects frontend):
            //   policy=confirm requires confirmed===true.
            //   policy=silent proceeds without confirmation.
            //   policy=blocked is already rejected above.
            //
            // The check happens here, INSIDE the transaction with lockForUpdate(),
            // so the policy is recalculated on the freshest state — not trusted
            // from any value the frontend might have sent earlier.
            if (
                config('voice.call_state_policy_enabled', false)
                && $policyResult['policy'] === 'confirm'
                && !$confirmed
            ) {
                return [
                    'state'   => 'confirmation_required',
                    'policy'  => 'confirm',
                    'reason'  => $policyResult['reason'],
                    'message' => 'Explicit confirmation is required to replace the active voice session.',
                    'session' => $existingSession,
                ];
            }

            // ── Mark existing sessions as replaced ────────────────────────
            foreach ($activeSessions as $activeSession) {
                $activeSession->forceFill([
                    'status'         => 'replaced',
                    'replaced_at'    => now(),
                    'failure_reason' => 'replaced_by_takeover',
                ])->save();
            }

            $ttl = (int) ($tenantSetting->session_ttl_seconds
                ?: config('voice.session_ttl_seconds', 28800));

            $session = VoiceSession::withoutGlobalScopes()->create([
                'tenant_id'              => $user->tenant_id,
                'user_id'                => $user->id,
                'voice_extension_id'     => $extension->id,
                'browser_tab_id'         => $browserTabId,
                'session_uuid'           => (string) Str::uuid(),
                'status'                 => 'pending',
                'last_seen_at'           => now(),
                'expires_at'             => now()->addSeconds($ttl),
                'ip'                     => $ip,
                'user_agent'             => $userAgent,
                'call_state'             => VoiceSession::CALL_STATE_NO_CALL,
                'call_state_updated_at'  => now(),
            ]);

            $rotated = $this->credentials->rotatePassword($extension);

            return [
                'state'  => 'created',
                'policy' => $policyResult['policy'],
                'session'   => $session,
                'extension' => $rotated['extension'],
                'sip' => [
                    'uri' => sprintf('sip:%s@%s',
                        $rotated['extension']->sip_username,
                        config('voice.realm')
                    ),
                    'authorization_user' => $rotated['extension']->sip_username,
                    'password'           => $rotated['password'],
                    'realm'              => config('voice.realm'),
                    'wss_server'         => config('voice.wss_server'),
                ],
            ];
        });

        if (($result['state'] ?? null) === 'created') {
            $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'voice_session_takeover');
        }

        return $result;
    }

    private function terminalizeLinkedActiveCallsForReconnect(
        VoiceSession $session,
        User $user
    ): ?array {
        $calls = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $session->tenant_id)
            ->where('voice_session_id', $session->id)
            ->whereIn('call_state', VoiceCall::ACTIVE_STATES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($calls->isEmpty()) {
            return null;
        }

        $postCommitAction = [
            'action' => self::RECONNECT_AGENT_ACTION_RESUME,
            'voice_call_id' => null,
        ];

        foreach ($calls as $call) {
            $targetState = $call->answered_at !== null
                ? 'ended'
                : 'cancelled';

            $updated = $this->voiceConversations->updateCallState(
                $call,
                $targetState
            );

            if (
                $updated->answered_at !== null
                && $postCommitAction['action'] !== self::RECONNECT_AGENT_ACTION_WRAP_UP
            ) {
                $postCommitAction = [
                    'action' => self::RECONNECT_AGENT_ACTION_WRAP_UP,
                    'voice_call_id' => $updated->id,
                ];
            }
        }

        return $postCommitAction;
    }

    private function applyReconnectPostCommitAgentTransition(
        User $user,
        ?array $transition
    ): void {
        if (!$transition || ($transition['action'] ?? null) === self::RECONNECT_AGENT_ACTION_NONE) {
            return;
        }

        if (($transition['action'] ?? null) === self::RECONNECT_AGENT_ACTION_WRAP_UP) {
            $this->agentStatus->enterVoiceWrapUp(
                $user,
                (int) $transition['voice_call_id']
            );

            $voiceCall = VoiceCall::withoutGlobalScopes()
                ->find((int) $transition['voice_call_id']);

            if ($voiceCall) {
                $this->telephonyWrapup->startFromVoiceCall($voiceCall);
            }

            return;
        }

        if (($transition['action'] ?? null) === self::RECONNECT_AGENT_ACTION_RESUME) {
            $this->agentStatus->resumeRequestedStatusAfterVoiceWork(
                $user,
                'voice_reconnect'
            );
        }
    }

    public function revoke(
        VoiceSession $session,
        string $reason = 'manual'
    ): VoiceSession {
        $shouldInvalidateCredentials = $this->isCurrentActiveSession(
            $session
        );

        $extension = $session->voiceExtension;

        if ($shouldInvalidateCredentials && $extension) {
            $this->credentials->invalidatePassword($extension);
        }

        $session->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'failure_reason' => Str::limit($reason, 255, ''),
        ])->save();

        $revoked = $session->fresh();
        $this->queueMemberAvailability->pauseExistingMembershipsForVoiceSession($revoked, 'voice_session_revoked');

        return $revoked;
    }

    private function isCurrentActiveSession(
        VoiceSession $session
    ): bool {
        if (!in_array($session->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        $currentSessionId = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $session->tenant_id)
            ->where('user_id', $session->user_id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->value('id');

        return (int) $currentSessionId === (int) $session->id;
    }

    private function restoreReadyAvailabilityOrFailClosed(VoiceSession $session, string $browserTabId, string $reason): void
    {
        try {
            $this->queueMemberAvailability->restoreReadyAvailabilityForSession($session, $browserTabId, $reason);
        } catch (Throwable $e) {
            if ($session->user) {
                $previousStatus = AgentSession::where('user_id', $session->user_id)->value('status');

                AgentSession::where('user_id', $session->user_id)->update([
                    'status' => 'offline',
                    'pause_reason_id' => null,
                    'status_since' => now(),
                ]);
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($session->user, $reason . '_compensation');

                // Diagnostic: this fail-closed path is a direct agent_sessions.status writer.
                Log::info('[AgentStatusWrite] agent_sessions.status set', [
                    'user_id'          => $session->user_id,
                    'tenant_id'        => $session->tenant_id,
                    'previous_status'  => $previousStatus,
                    'new_status'       => 'offline',
                    'is_offline_write' => true,
                    'reason'           => $reason . '_restore_fail_closed',
                    'writer'           => 'VoiceSessionService::restoreReadyAvailabilityOrFailClosed',
                    'voice_session_id' => $session->id,
                    'session_uuid'     => $session->session_uuid,
                    'browser_tab_id'   => $browserTabId,
                ]);
            }

            Log::warning('[QueueMemberAvailability] voice session availability restore failed closed', [
                'tenant_id' => $session->tenant_id,
                'user_id' => $session->user_id,
                'session_uuid' => $session->session_uuid,
                'reason' => $reason,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 300),
            ]);
        }
    }

    private function guardTab(
        VoiceSession $session,
        string $browserTabId
    ): void {
        if (!hash_equals(
            (string) $session->browser_tab_id,
            $browserTabId
        )) {
            throw new RuntimeException(
                'The browser tab does not own this voice session.'
            );
        }
    }
}
