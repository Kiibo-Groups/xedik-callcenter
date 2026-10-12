<?php

namespace App\Services;

use App\Models\AgentSession;
use App\Models\AgentStatusLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Voice\AsteriskQueueMemberAvailabilityService;
use App\Services\Voice\QueueMemberAvailabilityException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AgentStatusService
{
    public function __construct(
        private readonly AsteriskQueueMemberAvailabilityService $queueMemberAvailability
    ) {}

    public function changeStatus(User $user, string $status, ?int $pauseReasonId = null, ?string $browserTabId = null, string $source = 'change_status'): AgentSession
    {
        return $this->withUserAvailabilityLock($user, function () use ($user, $status, $pauseReasonId, $browserTabId, $source): AgentSession {
            if ($status !== 'ready') {
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, $status);
            }

            $session = $this->persistStatus($user, $status, $pauseReasonId, $source, $browserTabId);

            if ($status !== 'ready') {
                return $session;
            }

            try {
                $this->queueMemberAvailability->unpauseExistingMembershipsForReady($user, $browserTabId);
            } catch (QueueMemberAvailabilityException $e) {
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'ready_compensation');
                $this->persistStatus($user, 'offline', null, 'ready_compensation', $browserTabId);

                throw $e;
            } catch (Throwable $e) {
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'ready_failure_compensation');
                $this->persistStatus($user, 'offline', null, 'ready_failure_compensation', $browserTabId);

                Log::warning('[QueueMemberAvailability] ready transition failed closed', [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'error_class' => $e::class,
                    'message' => Str::limit($e->getMessage(), 300),
                ]);

                throw new QueueMemberAvailabilityException('Unable to enable Voice calls. You remain unavailable.', previous: $e);
            }

            return $session->fresh();
        });
    }

    private function persistStatus(User $user, string $status, ?int $pauseReasonId = null, string $source = 'change_status', ?string $browserTabId = null): AgentSession
    {
        return DB::transaction(function () use ($user, $status, $pauseReasonId, $source, $browserTabId) {
            // Diagnostic: capture the previous status + last_ping_at BEFORE the write so we
            // can attribute exactly who changed the agent's requested status and to what.
            $existing = AgentSession::withoutGlobalScopes()->where('user_id', $user->id)->first();

            // A REQUESTED-status change (manual Ready/Paused/Offline click, refresh-
            // to-offline safety via pagehide, logout) must never truncate an active
            // effective Voice occupancy row. AgentSession.status is always allowed to
            // change below; AgentStatusLog keeps recording in_call/wrap_up until that
            // work genuinely finishes (answered call ends / disposition is submitted).
            $activeVoiceOccupancy = $this->resolveActiveVoiceOccupancy($user);

            if ($activeVoiceOccupancy === null) {
                $this->transitionStatusLog($user, $status, $pauseReasonId, null, 'manual');
            }

            $values = [
                'tenant_id' => $user->tenant_id,
                'status'          => $status,
                'pause_reason_id' => $pauseReasonId,
                'pending_status_after_wrap_up' => null,
                'pending_pause_reason_id' => null,
                'status_since'    => now(),
                'last_ping_at'    => now(),
            ];

            if ($activeVoiceOccupancy === 'wrap_up') {
                $values = [
                    'tenant_id' => $user->tenant_id,
                    'status' => 'wrap_up',
                    'pause_reason_id' => null,
                    'pending_status_after_wrap_up' => $status,
                    'pending_pause_reason_id' => $status === 'paused' ? $pauseReasonId : null,
                    'status_since' => $existing?->status === 'wrap_up' && $existing->status_since !== null
                        ? $existing->status_since
                        : now(),
                    'last_ping_at' => now(),
                ];
            }

            $session = AgentSession::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id], $values);

            $this->logAgentStatusWrite($user, $existing?->status, $session->status, $source, $existing?->last_ping_at, $browserTabId, 'AgentStatusService::persistStatus');

            return $session;
        });
    }

    /**
     * Diagnostic-only structured log for every agent_sessions.status write, with special
     * emphasis on offline transitions (who set it, from what, and in which request context).
     * Never changes behavior.
     */
    private function logAgentStatusWrite(User $user, ?string $previousStatus, string $newStatus, string $source, mixed $lastPingAt, ?string $browserTabId, string $writer): void
    {
        try {
            $request = request();

            Log::info('[AgentStatusWrite] agent_sessions.status set', [
                'user_id'         => $user->id,
                'tenant_id'       => $user->tenant_id,
                'previous_status' => $previousStatus,
                'new_status'      => $newStatus,
                'is_offline_write' => $newStatus === 'offline',
                'reason'          => $source,
                'writer'          => $writer,
                'browser_tab_id'  => $browserTabId ?? $request?->header('X-XEDIK-Browser-Tab-Id'),
                'last_ping_at'    => $lastPingAt ? Carbon::parse($lastPingAt)->toIso8601String() : null,
                'request_path'    => $request?->path(),
                'request_method'  => $request?->method(),
                'ip'              => $request?->ip(),
                'user_agent'      => Str::limit((string) $request?->userAgent(), 200),
            ]);
        } catch (Throwable $e) {
            Log::warning('[AgentStatusWrite] diagnostic log failed', [
                'user_id' => $user->id,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 200),
            ]);
        }
    }

    /**
     * Phase 1E-B3: record a VOICE EFFECTIVE occupancy state (ringing/in_call/wrap_up)
     * in the AgentStatusLog timeline. Never touches AgentSession — the agent's
     * REQUESTED status (ready/paused/offline) is unaffected by ringing, taking, or
     * wrapping up a call. Idempotent: a no-op if the currently open row already has
     * this exact status.
     */
    public function transitionVoiceEffectiveStatus(User $user, string $status, ?int $voiceCallId, string $source): Carbon
    {
        if (!in_array($status, AgentStatusLog::EFFECTIVE_STATUSES, true)) {
            throw new \InvalidArgumentException("Not a Voice effective status: {$status}");
        }

        return $this->withUserAvailabilityLock($user, function () use ($user, $status, $voiceCallId, $source): Carbon {
            return DB::transaction(function () use ($user, $status, $voiceCallId, $source): Carbon {
                return $this->transitionStatusLog($user, $status, null, $voiceCallId, $source);
            });
        });
    }

    public function pauseQueueForOutboundVoice(User $user): void
    {
        $this->withUserAvailabilityLock($user, function () use ($user): void {
            AgentSession::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'tenant_id' => $user->tenant_id,
                    'status' => 'offline',
                    'pause_reason_id' => null,
                    'status_since' => now(),
                    'last_ping_at' => now(),
                ]
            );

            $result = $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'outbound_call');

            if ((int) ($result['failed'] ?? 0) > 0) {
                throw new QueueMemberAvailabilityException('Unable to protect inbound queue availability before starting the outbound call.');
            }
        });
    }

    public function restoreQueueAvailabilityForRequestedStatus(User $user, ?string $browserTabId = null, string $reason = 'voice_work_finished'): void
    {
        $this->withUserAvailabilityLock($user, function () use ($user, $browserTabId, $reason): void {
            $session = AgentSession::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->first();

            if (($session?->status ?? 'offline') === 'ready') {
                $this->queueMemberAvailability->unpauseExistingMembershipsForReady($user, $browserTabId, $reason);
                return;
            }

            $result = $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, $reason);
            if ((int) ($result['failed'] ?? 0) > 0) {
                throw new QueueMemberAvailabilityException('Unable to restore Voice queue availability safely.');
            }
        });
    }

    /**
     * Phase 1E-B3: an unanswered offer ended (Reject/Missed/Cancelled/timeout) or a
     * Voice Wrap-Up was cleared by a successful disposition — resume whatever the
     * agent's REQUESTED status already is (ready/paused/offline).
     *
     * Always writes a fresh AgentSession.status_since, even when the REQUESTED
     * status string is unchanged (e.g. it was already 'ready' before the call).
     * The effective timeline still advanced to a brand-new segment — the header
     * timer must reset to zero for it, never continue counting from whatever
     * Ready/Paused/Offline start time existed before Ringing began. Without this,
     * a stale status_since would make the post-call Ready/Paused/Offline segment
     * appear to have been running since before the call ever started.
     */
    public function resumeRequestedStatusAfterVoiceWork(User $user, string $source): void
    {
        $this->withUserAvailabilityLock($user, function () use ($user, $source): void {
            DB::transaction(function () use ($user, $source) {
                $session = AgentSession::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->first();

                $requested = $this->requestedStatusAfterVoiceWork($session);
                $pauseReasonId = $requested === 'paused'
                    ? ($session?->pending_pause_reason_id ?? $session?->pause_reason_id)
                    : null;

                $now = now();
                $this->transitionStatusLog($user, $requested, $pauseReasonId, null, $source, $now);

                AgentSession::withoutGlobalScopes()->where('user_id', $user->id)->update([
                    'status' => $requested,
                    'pause_reason_id' => $pauseReasonId,
                    'pending_status_after_wrap_up' => null,
                    'pending_pause_reason_id' => null,
                    'status_since' => $now,
                ]);
            });
        });
    }

    private function requestedStatusAfterVoiceWork(?AgentSession $session): string
    {
        $status = $session?->pending_status_after_wrap_up ?: $session?->status ?: 'offline';

        return $status === 'wrap_up' ? 'offline' : $status;
    }

    /**
     * Stages the REQUESTED status/pause reason selected via the Voice
     * disposition modal's "After Disposition Status" field. Deliberately does
     * NOT transition AgentStatusLog or touch queue_members — it only records
     * what the agent wants next. The open wrap_up row (and everything it
     * implies: header, queue pause, Conversation, VoiceCall) is completely
     * unaffected until exitVoiceWrapUpAfterDisposition() actually applies it
     * (Wrap-Up Truth Rule: selecting an option must not immediately apply it).
     *
     * This doubles as the multiple-pending-Wrap-Up deferral mechanism: if
     * another answered Voice Conversation still needs disposition,
     * exitVoiceWrapUpAfterDisposition() will no-op (hasOtherPendingVoiceWrapUp())
     * and simply leave this staged value in AgentSession until the FINAL
     * pending Wrap-Up is resolved, at which point resumeRequestedStatusAfterVoiceWork()
     * reads it and applies it for real. The most recently staged value wins if
     * multiple Wrap-Ups are dispositioned with different After Disposition
     * Status selections before the final one resolves.
     */
    public function stageRequestedStatusForVoiceDisposition(User $user, string $status, ?int $pauseReasonId): void
    {
        AgentSession::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'pending_status_after_wrap_up' => $status,
                'pending_pause_reason_id' => $status === 'paused' ? $pauseReasonId : null,
            ]
        );
    }

    /**
     * Phase 1E-B3: an answered call just ended — enter Wrap-Up and pause queue
     * membership. Never unpauses on its own; only a successful disposition
     * (exitVoiceWrapUpAfterDisposition) may restore availability.
     */
    public function enterVoiceWrapUp(User $user, int $voiceCallId): void
    {
        $wrapUpStartedAt = $this->transitionVoiceEffectiveStatus($user, 'wrap_up', $voiceCallId, 'voice');
        $existing = AgentSession::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $pendingStatus = $existing?->pending_status_after_wrap_up
            ?: ($existing?->status === 'wrap_up' ? null : ($existing?->status ?? 'offline'));
        $pendingPauseReasonId = $existing?->pending_pause_reason_id
            ?: ($pendingStatus === 'paused' ? $existing?->pause_reason_id : null);

        AgentSession::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'status' => 'wrap_up',
                'pause_reason_id' => null,
                'pending_status_after_wrap_up' => $pendingStatus,
                'pending_pause_reason_id' => $pendingPauseReasonId,
                'status_since' => $wrapUpStartedAt,
                'last_ping_at' => now(),
            ]
        );

        $this->queueMemberAvailability->pauseExistingMembershipsForUser($user, 'wrap_up');
    }

    /**
     * Phase 1E-B3: called after a Voice disposition is successfully saved.
     *
     * Multiple Wrap-Up safety: if the agent still has ANY OTHER answered Voice
     * Conversation requiring disposition, Wrap-Up is NOT exited and queue_members
     * stays paused — reuses Conversation::isVoiceWrapUpPending() (not rewritten).
     *
     * Otherwise resumes the requested status and, only if that requested status is
     * 'ready', unpauses queue membership. Paused/offline requested statuses keep
     * queue_members.paused=1 — no action needed, it is already 1.
     */
    public function exitVoiceWrapUpAfterDisposition(User $user, ?string $browserTabId = null): void
    {
        if ($this->hasOtherPendingVoiceWrapUp($user)) {
            return;
        }

        $this->resumeRequestedStatusAfterVoiceWork($user, 'disposition');

        $session = AgentSession::withoutGlobalScopes()->where('user_id', $user->id)->first();
        if (($session?->status ?? null) !== 'ready') {
            return;
        }

        try {
            $this->queueMemberAvailability->unpauseExistingMembershipsForReady($user, $browserTabId, 'disposition');
        } catch (Throwable $e) {
            // Best-effort: a disposition must never fail because Voice availability
            // could not be restored. The agent simply stays paused until Ready is
            // re-selected or the next successful transition retries this.
            Log::warning('[AgentStatusService] unpause after disposition failed', [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 300),
            ]);
        }
    }

    private function hasOtherPendingVoiceWrapUp(User $user): bool
    {
        return Conversation::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('assigned_to', $user->id)
            ->where('channel', 'voice')
            ->where('status', 'active')
            ->whereNull('disposition')
            ->with('latestVoiceCall')
            ->get()
            ->contains(fn (Conversation $c) => $c->isVoiceWrapUpPending());
    }

    /**
     * Count of the agent's remaining answered Voice Conversations still
     * awaiting disposition — exposed for the disposition response contract
     * (remaining_voice_wrap_up_count) so the frontend knows whether the header
     * is about to change or must stay on Wrap-Up for another pending call.
     */
    public function countPendingVoiceWrapUps(User $user): int
    {
        return Conversation::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('assigned_to', $user->id)
            ->where('channel', 'voice')
            ->where('status', 'active')
            ->whereNull('disposition')
            ->with('latestVoiceCall')
            ->get()
            ->filter(fn (Conversation $c) => $c->isVoiceWrapUpPending())
            ->count();
    }

    /**
     * Canonical effective-status resolver, priority order:
     * 1. in_call  — an answered, still-active Voice call exists
     * 2. ringing  — an unanswered, still-active Voice offer exists
     * 3. wrap_up  — an answered terminal Voice Conversation needs disposition
     * 4. offline  — requested status is offline (no valid session/ownership)
     * 5. paused   — requested status is paused
     * 6. ready
     *
     * Read-only; derives from actual current work (Conversation/VoiceCall), not
     * merely from the last AgentStatusLog row, so a stale/missed transition can
     * never make the header lie about what the agent is actually doing.
     *
     * Requested-offline (refresh-to-offline safety, logout, stale cleanup) is
     * checked AFTER in_call/ringing/wrap_up, not before — an active effective
     * Voice state must override requested offline until that work finishes.
     *
     * @return array{status: string, since: \Illuminate\Support\Carbon}
     */
    public function resolveEffectiveStatus(User $user): array
    {
        $session = AgentSession::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $requested = $session->status ?? 'offline';

        $voiceConversations = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('assigned_to', $user->id)
            ->where('channel', 'voice')
            ->where('status', 'active')
            ->with('latestVoiceCall')
            ->get();

        foreach ($voiceConversations as $conversation) {
            $call = $conversation->latestVoiceCall;
            if ($call && $call->call_state === 'active') {
                return ['status' => 'in_call', 'since' => $call->answered_at ?? $call->ringing_at ?? now()];
            }
        }

        foreach ($voiceConversations as $conversation) {
            $call = $conversation->latestVoiceCall;
            if ($call && in_array($call->call_state, ['ringing', 'connecting'], true)) {
                if ($call->direction === 'outbound') {
                    return ['status' => 'in_call', 'since' => $call->ringing_at ?? $call->created_at ?? now()];
                }

                return ['status' => 'ringing', 'since' => $call->ringing_at ?? now()];
            }
        }

        foreach ($voiceConversations as $conversation) {
            if ($conversation->isVoiceWrapUpPending()) {
                return ['status' => 'wrap_up', 'since' => $conversation->latestVoiceCall?->ended_at ?? now()];
            }
        }

        if ($requested === 'offline') {
            return ['status' => 'offline', 'since' => $session->status_since ?? now()];
        }

        if ($requested === 'paused') {
            return ['status' => 'paused', 'since' => $session->status_since ?? now()];
        }

        return ['status' => 'ready', 'since' => $session->status_since ?? now()];
    }

    /**
     * True when the agent has an authoritative effective Voice state (ringing/
     * in_call/wrap_up) that must lock the MANUAL requested-status endpoints
     * (global selector, mobile API status endpoint). Deliberately NOT used by
     * refresh-safety (VoiceSessionController::pagehide()) or logout — those
     * intentionally still force AgentSession to offline during active Voice
     * work (see resolveActiveVoiceOccupancy()'s preservation of the
     * AgentStatusLog row); only a manual Ready/Paused/Offline selection made
     * through the status endpoints is blocked by this check. The disposition
     * flow (exitVoiceWrapUpAfterDisposition/resumeRequestedStatusAfterVoiceWork)
     * never calls changeStatus() and is therefore unaffected either way.
     */
    public function isManualStatusChangeLocked(User $user): bool
    {
        return in_array(
            $this->resolveEffectiveStatus($user)['status'],
            ['ringing', 'in_call', 'wrap_up'],
            true
        );
    }

    /**
     * The authoritative Voice effective occupancy that must never be silently
     * overwritten by a REQUESTED status change — 'in_call' or 'wrap_up' only.
     * Independent of AgentSession.status, so it still reports correctly even
     * after requested status has already flipped to offline (e.g. mid-refresh,
     * where AgentSession is updated before the caller re-checks this).
     */
    private function resolveActiveVoiceOccupancy(User $user): ?string
    {
        $voiceConversations = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('assigned_to', $user->id)
            ->where('channel', 'voice')
            ->where('status', 'active')
            ->with('latestVoiceCall')
            ->get();

        foreach ($voiceConversations as $conversation) {
            $call = $conversation->latestVoiceCall;

            if ($call?->call_state === 'active') {
                return 'in_call';
            }

            if ($call?->direction === 'outbound'
                && in_array($call->call_state, ['connecting', 'ringing', 'held'], true)) {
                return 'in_call';
            }
        }

        foreach ($voiceConversations as $conversation) {
            if ($conversation->isVoiceWrapUpPending()) {
                return 'wrap_up';
            }
        }

        return null;
    }

    /**
     * Closes the current open AgentStatusLog row (if any) and opens the next one.
     * Idempotent: a no-op when the open row already has this exact status.
     * Single point of truth for the "at most one open row" invariant.
     */
    private function transitionStatusLog(
        User $user,
        string $status,
        ?int $pauseReasonId,
        ?int $voiceCallId,
        string $source,
        ?Carbon $now = null,
    ): Carbon {
        $prev = AgentStatusLog::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();

        if ($prev && $prev->status === $status) {
            return $prev->started_at;
        }

        $now ??= now();

        if ($prev) {
            $duration = (int) $prev->started_at->diffInSeconds($now);
            $prev->update([
                'ended_at'         => $now,
                'duration_seconds' => $duration,
            ]);
        }

        AgentStatusLog::withoutGlobalScopes()->create([
            'tenant_id'       => $user->tenant_id,
            'user_id'         => $user->id,
            'status'          => $status,
            'pause_reason_id' => $pauseReasonId,
            'voice_call_id'   => $voiceCallId,
            'source'          => $source,
            'started_at'      => $now,
        ]);

        return $now;
    }

    public function handleOfflineAgents(): void
    {
        $cutoff = now()->subMinutes(2);

        $stale = AgentSession::withoutGlobalScopes()
            ->whereNotIn('status', ['offline'])
            ->where('last_ping_at', '<', $cutoff)
            ->get();

        // Diagnostic: record every stale-cleanup scan and which agents it will force offline.
        Log::info('[AgentStatusWrite] stale cleanup scan', [
            'writer'        => 'AgentStatusService::handleOfflineAgents',
            'cutoff'        => $cutoff->toIso8601String(),
            'stale_seconds' => 120,
            'stale_count'   => $stale->count(),
            'stale_user_ids' => $stale->pluck('user_id')->all(),
        ]);

        foreach ($stale as $session) {
            Log::info('[AgentStatusWrite] stale cleanup marking agent offline', [
                'writer'          => 'AgentStatusService::handleOfflineAgents',
                'user_id'         => $session->user_id,
                'tenant_id'       => $session->tenant_id,
                'previous_status' => $session->status,
                'new_status'      => 'offline',
                'is_offline_write' => true,
                'reason'          => 'stale_agent_cleanup',
                'last_ping_at'    => optional($session->last_ping_at)->toIso8601String(),
                'cutoff'          => $cutoff->toIso8601String(),
            ]);

            if ($session->user) {
                $this->queueMemberAvailability->pauseExistingMembershipsForUser($session->user, 'stale_agent_cleanup');

                if ($this->resolveActiveVoiceOccupancy($session->user) !== null) {
                    continue;
                }

                $this->transitionStatusLog($session->user, 'offline', null, null, 'stale');
            }

            $session->update([
                'status'          => 'offline',
                'pause_reason_id' => null,
            ]);
        }
    }

    public function ping(User $agent): void
    {
        AgentSession::withoutGlobalScopes()
                    ->where('user_id', $agent->id)
                    ->update(['last_ping_at' => now()]);
    }

    private function withUserAvailabilityLock(User $user, callable $callback): mixed
    {
        $lock = Cache::lock('queue-member-availability:user:' . $user->id, 10);

        try {
            return $lock->block(5, $callback);
        } catch (Throwable $e) {
            if ($e instanceof QueueMemberAvailabilityException) {
                throw $e;
            }

            throw new RuntimeException('Agent status transition could not be completed.', previous: $e);
        }
    }
}
