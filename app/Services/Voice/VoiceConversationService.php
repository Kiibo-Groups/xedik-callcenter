<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Helpers\PhoneHelper;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\OmniQueue;
use App\Models\PreviewDialerOffer;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallerId;
use App\Models\VoiceSession;
use App\Models\VoiceTenantSetting;
use App\Services\PreviewDialerLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoiceConversationService
{
    public function __construct(
        private readonly PreviewDialerLifecycleService $lifecycleService
    ) {}

    /**
     * Find or create a Conversation + VoiceCall for a Voice interaction.
     *
     * Idempotent by (tenant_id, call_uuid) — repeated calls return the same record.
     * All normalization and lookup happens here; frontend phone values are not trusted directly.
     *
     * @param User   $agent      The authenticated agent making/taking the call.
     * @param string $callUuid   UUID stripped from the voice-local-{uuid} frontend ID.
     * @param string $direction  'inbound' | 'outbound'
     * @param string $rawPhone   Raw phone string as reported by the SIP stack.
     * @param string|null $sessionUuid   Voice session UUID for FK correlation (optional).
     * @param int|null $originConversationId  When call originates from an existing conversation.
     *
     * @return array{conversation: Conversation, voice_call: VoiceCall}
     */
    public function findOrCreate(
        User $agent,
        string $callUuid,
        string $direction,
        string $rawPhone,
        ?string $sessionUuid = null,
        ?int $originConversationId = null,
        ?int $campaignId = null,
        array $inboundIdentity = [],
        array $outboundCallerId = [],
        ?int $explicitQueueId = null,
    ): array {
        $tenantId = $agent->tenant_id;
        $identity = $this->normalizeInboundIdentity($direction, $rawPhone, $inboundIdentity);

        try {
            return DB::transaction(function () use (
            $agent, $callUuid, $direction, $rawPhone, $sessionUuid,
            $originConversationId, $campaignId, $tenantId, $identity,
            $outboundCallerId, $explicitQueueId
        ) {
            // ── Idempotency check ────────────────────────────────────────────
            $existingByLinkedid = $this->findExistingInboundCall($tenantId, $direction, $identity['asterisk_linkedid']);
            if ($existingByLinkedid) {
                return $this->reuseExistingInboundCall($existingByLinkedid, $agent, $sessionUuid, $identity);
            }

            $existing = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('call_uuid', $callUuid)
                ->with('conversation')
                ->first();

            if ($existing) {
                return [
                    'conversation' => $existing->conversation,
                    'voice_call'   => $existing,
                    'created'      => false,
                    'reused'       => true,
                ];
            }

            // ── Normalize phone (backend is authoritative) ──────────────────
            $aniRaw = $identity['ani'] ?: $rawPhone;
            $normalizedPhone = PhoneHelper::normalizeNanpForReporting($aniRaw) ?: PhoneHelper::normalizeForSearch($aniRaw);
            $displayPhone    = PhoneHelper::formatNanpDisplay($normalizedPhone ?: $aniRaw) ?: ($normalizedPhone ?: $aniRaw);

            // ── Resolve Lead ─────────────────────────────────────────────────
            $resolvedQueue = $this->resolveInboundQueueByName($tenantId, $identity['asterisk_queue_name'])
                ?? $this->resolveExplicitOutboundQueue($direction, $tenantId, $explicitQueueId)
                ?? $this->resolveQueue(
                    $direction, $tenantId, $agent, null, $originConversationId
                );

            $effectiveCampaignId = $direction === 'inbound'
                ? $resolvedQueue?->campaign_id
                : $campaignId;

            $lead = $this->resolveLead($normalizedPhone, $tenantId, $agent, $effectiveCampaignId);

            // ── Resolve Queue & Campaign ─────────────────────────────────────
            // Queue already resolved before lead lookup so inbound ANI is campaign scoped.

            // ── Resolve VoiceSession FK (optional) ──────────────────────────
            $voiceSessionId = null;
            if ($sessionUuid) {
                $voiceSessionId = VoiceSession::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $agent->id)
                    ->where('session_uuid', $sessionUuid)
                    ->value('id');
            }

            // ── Create Conversation ──────────────────────────────────────────
            $conversation = Conversation::create([
                'tenant_id'     => $tenantId,
                'channel'       => 'voice',
                'direction'     => $direction,
                'status'        => 'active',
                'omni_queue_id' => $resolvedQueue?->id,
                'campaign_id'   => $effectiveCampaignId,
                'assigned_to'   => $agent->id,
                'lead_id'       => $lead?->id,
                // from_email stores the normalized remote phone (semantic alias via accessor)
                'from_email'    => $normalizedPhone,
                'from_name'     => $lead ? trim($lead->first_name . ' ' . $lead->last_name) : null,
                'external_id'   => $callUuid,   // call_uuid doubles as external_id for dedup
                'subject'       => $displayPhone,
                'wait_since'    => now(),
                'assigned_at'   => now(),
            ]);

            // ── Resolve Preview Caller ID (outbound only) ────────────────────
            $offerCallerId    = null;
            $offerCallerIdId  = null;
            if ($direction === 'outbound') {
                if (!empty($outboundCallerId['caller_id_number'])) {
                    $offerCallerId = (string) $outboundCallerId['caller_id_number'];
                    $offerCallerIdId = !empty($outboundCallerId['voice_caller_id_id'])
                        ? (int) $outboundCallerId['voice_caller_id_id']
                        : null;
                } else {
                    $resolved = $this->resolveOfferCallerId($callUuid, $tenantId);
                    if ($resolved) {
                        $offerCallerId   = $resolved->number;
                        $offerCallerIdId = $resolved->id;
                    }
                }
            }

            // ── Create VoiceCall ─────────────────────────────────────────────
            // Outbound calls start in 'connecting' with ringing_at=null.
            // ringing_at is set only when the backend receives a connecting→ringing
            // transition (triggered by a real SIP 180 Ringing from the remote end).
            // This prevents pre-ringing failures (NO_ROUTE_DESTINATION, fast 4xx)
            // from being counted as valid call attempts.
            // Inbound calls start in 'ringing' because the phone is already alerting.
            $voiceCall = VoiceCall::create([
                'tenant_id'           => $tenantId,
                'conversation_id'     => $conversation->id,
                'voice_session_id'    => $voiceSessionId,
                'agent_user_id'       => $agent->id,
                'call_uuid'           => $callUuid,
                'asterisk_linkedid'   => $identity['asterisk_linkedid'],
                'asterisk_queue_name' => $identity['asterisk_queue_name'],
                'sip_call_id'         => $identity['sip_call_id'],
                'direction'           => $direction,
                'from_number'         => $direction === 'outbound' ? $offerCallerId : $aniRaw,
                'to_number'           => $direction === 'outbound' ? $rawPhone : $identity['dnis'],
                'remote_number'       => $normalizedPhone,
                'remote_display_name' => $lead ? trim($lead->first_name . ' ' . $lead->last_name) : null,
                'call_state'          => $direction === 'inbound' ? 'ringing' : 'connecting',
                'ringing_at'          => $direction === 'inbound' ? now() : null,
                'voice_caller_id_id'  => $offerCallerIdId,
            ]);

            // Explicit array — avoids compact() snake_case vs camelCase ambiguity.
            Log::info('[InboundAcdIdentity] voice conversation created', [
                'tenant_id' => $tenantId,
                'user_id' => $agent->id,
                'queue_name' => $identity['asterisk_queue_name'],
                'queue_id' => $resolvedQueue?->id,
                'campaign_id' => $effectiveCampaignId,
                'linkedid' => $identity['asterisk_linkedid'],
                'conversation_id' => $conversation->id,
                'voice_call_id' => $voiceCall->id,
            ]);

            return [
                'conversation' => $conversation,
                'voice_call'   => $voiceCall,
                'created'      => true,
                'reused'       => false,
            ];
            });
        } catch (QueryException $e) {
            if ($identity['asterisk_linkedid'] && $this->isDuplicateKeyException($e)) {
                $existing = $this->findExistingInboundCall($tenantId, $direction, $identity['asterisk_linkedid']);
                if ($existing) {
                    Log::info('[InboundAcdIdentity] duplicate insert recovered', [
                        'tenant_id' => $tenantId,
                        'user_id' => $agent->id,
                        'linkedid' => $identity['asterisk_linkedid'],
                        'voice_call_id' => $existing->id,
                    ]);

                    return $this->reuseExistingInboundCall($existing, $agent, $sessionUuid, $identity);
                }
            }

            throw $e;
        }
    }

    /**
     * Transition a VoiceCall to a new state.
     *
     * Validates the transition before applying. Idempotent for same→same state.
     * Backend calculates all timestamps; browser hints are ignored for security.
     * Terminal states cannot transition back to active states.
     *
     * @return VoiceCall the updated record
     * @throws \InvalidArgumentException when the transition is not allowed
     */
    public function updateCallState(
        VoiceCall $call,
        string $newState
    ): VoiceCall {
        if (!in_array($newState, VoiceCall::ALL_STATES, true)) {
            throw new \InvalidArgumentException("Unknown call state: {$newState}");
        }

        // Idempotent: same state is a no-op
        if ($call->call_state === $newState) {
            return $call;
        }

        if (!$call->canTransitionTo($newState)) {
            throw new \InvalidArgumentException(
                "Cannot transition voice call from '{$call->call_state}' to '{$newState}'."
            );
        }

        $oldState = $call->call_state;
        $updates  = ['call_state' => $newState];
        $now      = now();

        match ($newState) {
            'ringing'    => $updates['ringing_at']  = $updates['ringing_at']  ?? $now,
            'connecting' => null,
            'active'     => $updates['answered_at'] = $updates['answered_at'] ?? $now,
            'held'       => null,
            'ended', 'rejected', 'failed', 'missed', 'busy',
            'no_answer', 'congestion', 'unavailable', 'cancelled' => $this->applyTerminalUpdates(
                $updates, $call, $newState, $now
            ),
            default => null,
        };

        $call->forceFill($updates)->save();
        $fresh = $call->fresh();

        if ($oldState === 'connecting' && $newState === 'ringing') {
            $this->lifecycleService->countAttemptAtRinging($fresh);
        }

        if (in_array($newState, VoiceCall::TERMINAL_STATES, true)) {
            $this->lifecycleService->finalizeAtEnd($fresh);
        }

        return $fresh;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param array<string, string|null> $inboundIdentity
     * @return array{ani: ?string, dnis: ?string, asterisk_queue_name: ?string, asterisk_linkedid: ?string, sip_call_id: ?string}
     */
    private function normalizeInboundIdentity(string $direction, string $rawPhone, array $inboundIdentity): array
    {
        if ($direction !== 'inbound') {
            return [
                'ani' => null,
                'dnis' => null,
                'asterisk_queue_name' => null,
                'asterisk_linkedid' => null,
                'sip_call_id' => null,
            ];
        }

        return [
            'ani' => $this->cleanIdentityString($inboundIdentity['ani'] ?? $rawPhone, 80),
            'dnis' => $this->cleanIdentityString($inboundIdentity['dnis'] ?? null, 80),
            'asterisk_queue_name' => $this->cleanIdentityString($inboundIdentity['queue_name'] ?? null, 128),
            'asterisk_linkedid' => $this->cleanIdentityString($inboundIdentity['asterisk_linkedid'] ?? null, 64),
            'sip_call_id' => $this->cleanIdentityString($inboundIdentity['sip_call_id'] ?? null, 255),
        ];
    }

    private function cleanIdentityString(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value) ?? '');

        return $clean === '' ? null : substr($clean, 0, $max);
    }

    private function findExistingInboundCall(int $tenantId, string $direction, ?string $linkedid): ?VoiceCall
    {
        if ($direction !== 'inbound' || blank($linkedid)) {
            return null;
        }

        return VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'inbound')
            ->where('asterisk_linkedid', $linkedid)
            ->with('conversation')
            ->first();
    }

    private function reuseExistingInboundCall(
        VoiceCall $call,
        User $agent,
        ?string $sessionUuid,
        array $identity
    ): array {
        $updates = [];

        if (!$call->from_number && $identity['ani']) {
            $updates['from_number'] = $identity['ani'];
        }
        if (!$call->remote_number && $identity['ani']) {
            $updates['remote_number'] = PhoneHelper::normalizeForSearch($identity['ani']);
        }
        if (!$call->to_number && $identity['dnis']) {
            $updates['to_number'] = $identity['dnis'];
        }
        if (!$call->asterisk_queue_name && $identity['asterisk_queue_name']) {
            $updates['asterisk_queue_name'] = $identity['asterisk_queue_name'];
        }
        if (!$call->sip_call_id && $identity['sip_call_id']) {
            $updates['sip_call_id'] = $identity['sip_call_id'];
        }

        $voiceSessionId = $this->resolveVoiceSessionId((int) $agent->tenant_id, (int) $agent->id, $sessionUuid);
        if ($voiceSessionId && (int) $call->voice_session_id !== (int) $voiceSessionId) {
            $updates['voice_session_id'] = $voiceSessionId;
        }

        if ($call->isTerminal()) {
            $updates['call_state'] = 'ringing';
            $updates['ringing_at'] = now();
            $updates['ended_at'] = null;
            $updates['duration_seconds'] = null;
            $updates['talk_seconds'] = null;
            $updates['hangup_cause'] = null;
        }

        if ($updates !== []) {
            $call->forceFill($updates)->save();
            $call = $call->fresh('conversation');
        }

        $canReassignReofferedCall = $call->conversation?->status === 'active';
        $conversation = $this->reassignReofferedConversation($call->conversation, $agent);
        if ($canReassignReofferedCall
            && $conversation
            && (int) $conversation->assigned_to === (int) $agent->id
            && (int) $call->agent_user_id !== (int) $agent->id) {
            $call->forceFill(['agent_user_id' => $agent->id])->save();
            $call = $call->fresh('conversation');
        }

        Log::info('[InboundAcdIdentity] voice conversation reused', [
            'tenant_id' => $agent->tenant_id,
            'user_id' => $agent->id,
            'queue_name' => $identity['asterisk_queue_name'],
            'linkedid' => $identity['asterisk_linkedid'],
            'conversation_id' => $call->conversation_id,
            'voice_call_id' => $call->id,
        ]);

        return [
            'conversation' => $conversation,
            'voice_call' => $call,
            'created' => false,
            'reused' => true,
        ];
    }

    /**
     * Reassign the parent Conversation to the agent currently receiving a reoffer of the
     * same inbound call (same tenant_id + asterisk_linkedid), when the Queue offers it to
     * a different agent than the one it was previously assigned to.
     *
     * Trusted path only: called exclusively from reuseExistingInboundCall(), which is only
     * reached via the authenticated inbound-conversation persistence endpoint after the
     * VoiceCall was already correlated by LinkedID. There is no way for a browser to trigger
     * this reassignment for an arbitrary conversation_id.
     *
     * A conversation that already resolved (agent submitted a disposition) is left untouched —
     * a stray/late reoffer must not resurrect it or hand it to a new agent.
     */
    private function reassignReofferedConversation(?Conversation $conversation, User $agent): ?Conversation
    {
        if (!$conversation || $conversation->status !== 'active') {
            return $conversation;
        }

        if ((int) $conversation->assigned_to === (int) $agent->id) {
            return $conversation;
        }

        $conversation->forceFill([
            'assigned_to' => $agent->id,
            'assigned_at' => now(),
        ])->save();

        Log::info('[InboundAcdIdentity] conversation reassigned on reoffer', [
            'tenant_id' => $agent->tenant_id,
            'conversation_id' => $conversation->id,
            'previous_assigned_to' => $conversation->getOriginal('assigned_to'),
            'new_assigned_to' => $agent->id,
        ]);

        return $conversation->fresh();
    }

    private function resolveVoiceSessionId(int $tenantId, int $userId, ?string $sessionUuid): ?int
    {
        if (!$sessionUuid) {
            return null;
        }

        $id = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('session_uuid', $sessionUuid)
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $driverCode === '1062'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    private function resolveInboundQueueByName(int $tenantId, ?string $queueName): ?OmniQueue
    {
        if (blank($queueName)) {
            Log::info('[InboundAcdIdentity] degraded queue context: missing queue header', [
                'tenant_id' => $tenantId,
            ]);
            return null;
        }

        if (!preg_match('/^[a-z0-9_]{1,128}$/', (string) $queueName)) {
            Log::warning('[InboundAcdIdentity] invalid queue header ignored', [
                'tenant_id' => $tenantId,
                'queue_name' => $queueName,
            ]);
            return null;
        }

        $queue = OmniQueue::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->whereHas('voiceSettings', fn ($query) => $query
                ->where('is_enabled', true)
                ->where('asterisk_queue_name', $queueName))
            ->with('voiceSettings')
            ->first();

        if (!$queue || !$queue->hasVoiceRoutingEnabled()) {
            Log::warning('[InboundAcdIdentity] queue header did not resolve to enabled tenant voice queue', [
                'tenant_id' => $tenantId,
                'queue_name' => $queueName,
            ]);
            return null;
        }

        return $queue;
    }

    private function applyTerminalUpdates(
        array &$updates,
        VoiceCall $call,
        string $newState,
        \Carbon\Carbon $now
    ): void {
        $updates['ended_at'] = $now;

        // Use Unix timestamps for arithmetic — avoids Carbon 3.x diffInSeconds argument-order
        // ambiguity and any timezone parsing differences with SQLite datetime strings.
        $nowTs = $now->timestamp;

        // duration: ringing_at → ended_at (total call time including ring)
        if ($call->ringing_at) {
            $updates['duration_seconds'] = max(0, $nowTs - $call->ringing_at->timestamp);
        }

        // talk_seconds: answered_at → ended_at (actual talk/connected time)
        if ($call->answered_at) {
            $updates['talk_seconds'] = max(0, $nowTs - $call->answered_at->timestamp);
        }

        if ($newState === 'missed')       $updates['hangup_cause'] = 'NO_ANSWER';
        if ($newState === 'rejected')     $updates['hangup_cause'] = 'REJECTED';
        if ($newState === 'busy')        $updates['hangup_cause'] = 'BUSY';
        if ($newState === 'no_answer')   $updates['hangup_cause'] = 'NO_ANSWER';
        if ($newState === 'congestion')  $updates['hangup_cause'] = 'CONGESTION';
        if ($newState === 'unavailable') $updates['hangup_cause'] = 'NO_ROUTE_DESTINATION';
        if ($newState === 'cancelled')   $updates['hangup_cause'] = 'CANCEL';
    }

    /**
     * Resolve the remote lead by normalized phone, scoped to tenant and agent campaigns.
     *
     * Mirrors the existing Console lead-search behavior:
     *   - Only leads within the agent's assigned campaigns
     *   - Exact or suffix-10 match on phone column
     *   - Returns null when ambiguous (multiple matches) — agent can associate manually
     */
    private function resolveLead(
        string $normalizedPhone,
        int $tenantId,
        User $agent,
        ?int $campaignId = null
    ): ?Lead {
        if (strlen($normalizedPhone) < 4) {
            return null;
        }

        try {
            $candidates = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->when(
                    $campaignId,
                    fn ($query) => $query->where('campaign_id', (int) $campaignId),
                    fn ($query) => $query->whereIn('campaign_id', $agent->campaigns()->pluck('campaigns.id'))
                )
                ->where(function ($q) use ($normalizedPhone) {
                    $q->where('phone', $normalizedPhone)
                      ->orWhere('phone', 'like', "%{$normalizedPhone}");
                })
                ->limit(2)
                ->get();

            return $candidates->count() === 1 ? $candidates->first() : null;
        } catch (\Throwable $e) {
            Log::warning('VoiceConversationService: lead lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Manual Dial only: use the exact queue the agent selected and the backend
     * already validated (QuickCallPreparationService::queueForCampaign), instead of
     * the tenant default queue. Scoped to outbound so inbound and Preview Dial keep
     * their existing resolution untouched. A defensive tenant/active re-check keeps
     * this safe even though the caller already validated the queue.
     */
    private function resolveExplicitOutboundQueue(string $direction, int $tenantId, ?int $explicitQueueId): ?OmniQueue
    {
        if ($direction !== 'outbound' || !$explicitQueueId) {
            return null;
        }

        return OmniQueue::withoutGlobalScopes()
            ->where('id', $explicitQueueId)
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->first();
    }

    /**
     * Resolve the OmniQueue for the Voice conversation.
     *
     * Priority (per spec):
     *   1. Queue/campaign of the origin conversation (when call starts from another channel)
     *   2. Explicit queue from agent context (not implemented in Phase 1 — reserved)
     *   3. VoiceTenantSetting.default_voice_queue_id
     *   4. null with diagnostic log
     */
    private function resolveQueue(
        string $direction,
        int $tenantId,
        User $agent,
        ?Lead $lead,
        ?int $originConversationId
    ): ?OmniQueue {
        // 1. Origin conversation
        if ($originConversationId) {
            $originQueue = Conversation::withoutGlobalScopes()
                ->where('id', $originConversationId)
                ->where('tenant_id', $tenantId)
                ->value('omni_queue_id');

            if ($originQueue) {
                $q = OmniQueue::withoutGlobalScopes()
                    ->where('id', $originQueue)
                    ->where('tenant_id', $tenantId)
                    ->where('active', true)
                    ->first();
                if ($q) return $q;
            }
        }

        // 2. (Reserved — explicit queue context not implemented in Phase 1)

        // 3. VoiceTenantSetting.default_voice_queue_id
        $voiceQueueId = VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->value('default_voice_queue_id');

        if ($voiceQueueId) {
            $q = OmniQueue::withoutGlobalScopes()
                ->where('id', $voiceQueueId)
                ->where('tenant_id', $tenantId)
                ->where('active', true)
                ->first();
            if ($q) return $q;
        }

        // 4. null — diagnostic
        Log::info('VoiceConversationService: no queue resolved for voice conversation', [
            'tenant_id'  => $tenantId,
            'direction'  => $direction,
            'agent_id'   => $agent->id,
        ]);

        return null;
    }

    /**
     * Look up the VoiceCallerId stored on the accepted preview offer for this call_uuid.
     * Returns null when there is no offer, or when the offer has no caller ID assigned.
     * Never throws — caller ID is best-effort; absence falls back to agent default.
     */
    private function resolveOfferCallerId(string $callUuid, int $tenantId): ?VoiceCallerId
    {
        try {
            $voiceCallerIdId = PreviewDialerOffer::withoutGlobalScopes()
                ->where('call_uuid', $callUuid)
                ->where('tenant_id', $tenantId)
                ->value('voice_caller_id_id');

            if (!$voiceCallerIdId) {
                return null;
            }

            return VoiceCallerId::withoutGlobalScopes()->find((int) $voiceCallerIdId);
        } catch (\Throwable $e) {
            Log::warning('VoiceConversationService: resolveOfferCallerId failed', [
                'call_uuid' => $callUuid,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }
}
