<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentSession;
use App\Models\Conversation;
use App\Models\DialerCampaign;
use App\Models\LeadList;
use App\Models\PreviewDialerOffer;
use App\Models\Script;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\Campaign;
use App\Models\OmniQueue;
use App\Services\Dialer\CallingHoursComplianceService;
use App\Services\Voice\GatewayResolutionService;
use App\Services\Voice\OutboundCallPreparationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PreviewDialerOfferService
{
    private const ACTIVE_STATUSES = ['pending', 'accepted'];
    private const ELIGIBLE_LEAD_STATUSES = ['NEW', 'NO_ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'REJECTED'];

    // Federal Calling Hours Guard is opt-in per campaign (compliance_config).
    // When enabled, candidates are scanned in this batch size per query pass
    // looking for one inside the caller's local calling window.
    private const CALLING_HOURS_SCAN_LIMIT = 100;
    private const MAX_CONCURRENT_CLAIM_ATTEMPTS = 10;

    // Phase 1 Fair Pull Distribution.
    // How many ordered eligible candidates to scan per pass while applying the
    // decline-rotation fairness guard. The existing leadQuery() ordering/filtering is
    // preserved; we only skip candidates the requesting agent already declined this round
    // while another eligible agent has not tried them, then take the first assignable one.
    private const FAIRNESS_SCAN_LIMIT = 100;

    // All-agents-declined hold: when every currently-eligible agent has already declined a
    // lead this round, park it for this many minutes so it leaves the eligible pool instead
    // of looping back to the same agent. No called_count change, no completion, no migration.
    private const ALL_DECLINED_HOLD_MINUTES = 10;

    public function __construct(
        private DialerTableService $tableService,
        private PreviewDialerLifecycleService $lifecycleService,
        private CallerIdResolutionService $callerIdService,
        private CampaignFilterService $filterService,
        private CallingHoursComplianceService $complianceService,
        private GatewayResolutionService $gatewayResolutionService,
        private OutboundCallPreparationService $outboundPreparation,
    ) {}

    /**
     * @return array{eligible: bool, reason: ?string, has_active_offer: bool}
     */
    public function eligibilityFor(User $user): array
    {
        $tenantId = (int) $user->tenant_id;

        if ($tenantId < 1) {
            return ['eligible' => false, 'reason' => 'not_eligible', 'has_active_offer' => false];
        }

        if (!$this->previewEnabled($tenantId)) {
            return ['eligible' => false, 'reason' => 'preview_disabled', 'has_active_offer' => false];
        }

        if (!$this->agentIsReady($user)) {
            $this->opportunisticallyExpirePendingOffer($user);
            return ['eligible' => false, 'reason' => 'agent_not_ready', 'has_active_offer' => false];
        }

        if ($this->agentIsInVoiceWrapUp($user)) {
            return ['eligible' => false, 'reason' => 'agent_in_wrap_up', 'has_active_offer' => false];
        }

        if ($this->currentOffer($user)) {
            return ['eligible' => false, 'reason' => 'active_offer_exists', 'has_active_offer' => true];
        }

        $running = $this->runningPreviewCampaigns($tenantId);
        if ($running->isEmpty()) {
            return ['eligible' => false, 'reason' => 'no_running_campaigns', 'has_active_offer' => false];
        }

        $assigned = $this->eligibleCampaignsForUser($user);
        if ($assigned->isEmpty()) {
            return ['eligible' => false, 'reason' => 'not_assigned', 'has_active_offer' => false];
        }

        foreach ($assigned as $campaign) {
            if (!$this->campaignConfigurationIsValid($campaign)) {
                continue;
            }

            return ['eligible' => true, 'reason' => null, 'has_active_offer' => false];
        }

        return ['eligible' => false, 'reason' => 'schema_not_prepared', 'has_active_offer' => false];
    }

    /**
     * @return array{offer: ?PreviewDialerOffer, created: bool, reason: ?string}
     */
    public function claim(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            if (!$this->agentIsReady($user)) {
                $this->opportunisticallyExpirePendingOffer($user);
                return ['offer' => null, 'created' => false, 'reason' => 'agent_not_ready'];
            }

            if ($this->agentIsInVoiceWrapUp($user)) {
                return ['offer' => null, 'created' => false, 'reason' => 'agent_in_wrap_up'];
            }

            $existing = $this->currentOfferForUpdate($user);
            if ($existing) {
                return ['offer' => $existing, 'created' => false, 'reason' => null];
            }

            $eligibility = $this->eligibilityFor($user);
            if (!$eligibility['eligible']) {
                return ['offer' => null, 'created' => false, 'reason' => $eligibility['reason']];
            }

            $blockedByCallingHours = false;

            foreach ($this->eligibleCampaignsForUser($user) as $campaign) {
                if (!$this->campaignConfigurationIsValid($campaign)) {
                    continue;
                }

                for ($attempt = 1; $attempt <= self::MAX_CONCURRENT_CLAIM_ATTEMPTS; $attempt++) {
                    $candidate = $this->nextEligibleLead($campaign, $user, lockForUpdate: true);
                    $lead = $candidate['lead'];

                    if (!$lead) {
                        if ($candidate['blocked_by_calling_hours']) {
                            $blockedByCallingHours = true;
                        } elseif (!empty($candidate['skipped_for_fairness'])) {
                            // Eligible leads exist but were fairly withheld from this agent
                            // (declined this round / all-declined hold). Another agent can
                            // still take them, so do NOT auto-complete the campaign.
                        } else {
                            $this->tryAutoCompleteIfExhausted($campaign);
                        }
                        continue 2;
                    }

                    if (!$this->reserveLeadForOffer($campaign, $user, $lead)) {
                        continue;
                    }

                    try {
                        $offer = $this->createOffer($campaign, $user, $lead);
                    } catch (QueryException $exception) {
                        $this->releaseReservedCandidate($campaign, $user, $lead);

                        $existing = $this->currentOfferForUpdate($user);
                        if ($existing) {
                            return ['offer' => $existing, 'created' => false, 'reason' => null];
                        }

                        if ($this->isRetryableClaimConflict($exception)) {
                            continue;
                        }

                        throw $exception;
                    }

                    return ['offer' => $offer->fresh(['dialerCampaign', 'leadList']), 'created' => true, 'reason' => null];
                }
            }

            return [
                'offer'   => null,
                'created' => false,
                'reason'  => $blockedByCallingHours ? 'blocked_by_calling_hours' : 'no_eligible_leads',
            ];
        }, 3);
    }

    public function currentOffer(User $user): ?PreviewDialerOffer
    {
        $offer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['dialerCampaign', 'leadList'])
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNull('finalized_at') // a finalized offer is never the current offer
            ->first();

        if ($this->expireIfPendingOfferIsStale($offer)) {
            return null;
        }

        if ($offer?->status === 'pending' && $this->agentIsInVoiceWrapUp($user)) {
            return null;
        }

        // Defensive final guard: only ever surface a genuinely-live current offer. This keeps
        // the frontend from ever receiving an expired/declined/completed/finalized offer as
        // the current one (e.g. if a row was terminalized in-place, by a concurrent path, or
        // by stale/rolled-back code), which would otherwise leave the agent stuck.
        if (!$this->isReturnableCurrentOffer($offer)) {
            return null;
        }

        return $offer;
    }

    /**
     * A returnable "current" offer is genuinely live for this agent: it still holds the active
     * slot, is pending or accepted, and carries no terminal marker. Pending offers additionally
     * must have no expired_at / declined_at (a live pending offer was neither expired nor
     * declined). finalized_at is always null for a live offer (it is stamped on expire/decline
     * and on disposition completion).
     */
    private function isReturnableCurrentOffer(?PreviewDialerOffer $offer): bool
    {
        if (!$offer) {
            return false;
        }
        if ((int) $offer->active_slot !== 1) {
            return false;
        }
        if (!in_array($offer->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }
        if ($offer->finalized_at !== null) {
            return false;
        }
        if ($offer->expired_at !== null || $offer->declined_at !== null) {
            return false;
        }

        return true;
    }

    public function accept(User $user, string $token): PreviewDialerOffer
    {
        return DB::transaction(function () use ($user, $token): PreviewDialerOffer {
            $offer = $this->offerForUserByTokenForUpdate($user, $token);

            if ($offer->status === 'accepted') {
                return $offer;
            }

            if ($offer->status !== 'pending') {
                throw new PreviewDialerOfferException('invalid_offer_status', 409);
            }

            if ($offer->expires_at && $offer->expires_at->isPast()) {
                $this->expireOffer($offer);
                throw new PreviewDialerOfferException('offer_expired', 409);
            }

            $campaign = $offer->dialerCampaign;
            if (!$campaign || $campaign->status !== 'running') {
                throw new PreviewDialerOfferException('campaign_not_running', 409);
            }

            if (!$this->agentIsReady($user)) {
                throw new PreviewDialerOfferException('agent_not_ready', 409);
            }

            $lead = DB::table($offer->leadList->table_name)
                ->where('id', (int) $offer->lead_list_row_id)
                ->first();

            if (!$lead || (int) ($lead->reserved_by ?? 0) !== (int) $user->id) {
                throw new PreviewDialerOfferException('lead_reservation_lost', 409);
            }

            // ── Resolve the outbound engine inputs from the Dialer Campaign ──────
            // Preview Dialer is the same outbound engine as Manual Dial; it differs only
            // by call_mode (PREVIEW vs MANUAL). It resolves its own CRM Campaign, operational
            // Queue (dialer_campaigns.omni_queue_id) and Script here, then delegates the
            // identical preparation pipeline.
            $crmCampaign = $this->resolvePreviewCrmCampaign($campaign);
            $queue       = $this->resolvePreviewQueue($campaign, $crmCampaign);
            $script      = $this->resolvePreviewScript($campaign, $crmCampaign);

            // Generate call_uuid on first accept so the frontend can start JsSIP
            // with the backend-assigned UUID, enabling idempotent Conversation creation.
            $callUuid = $offer->call_uuid ?? (string) Str::uuid();

            // Resolve Caller ID for this campaign (LRU rotation). Preview sends the SAME 7
            // SIP headers as Manual Dial, so X-xedik-Caller-ID is mandatory — a campaign
            // with no usable caller-ID pool cannot dial (mirrors Manual, which throws when
            // no caller ID is configured). Failing here rolls back the LRU last_used_at
            // advance via the surrounding transaction, so a blocked accept never burns a
            // rotation slot.
            $callerId = $this->callerIdService->resolveForCampaign($campaign);
            if ($callerId === null) {
                throw new PreviewDialerOfferException('caller_id_not_configured', 409);
            }

            // Resolve outbound gateway hidden_prefix (Campaign -> tenant setting ->
            // tenant-accessible default -> none). Also mandatory for the 7-header set: a
            // campaign with no resolvable gateway prefix cannot dial (mirrors Manual, which
            // throws 'No outbound voice gateway is configured').
            $dialPrefix = $this->gatewayResolutionService->resolveDialPrefixForDialerCampaign($campaign);
            if ($dialPrefix === null || trim((string) $dialPrefix) === '') {
                throw new PreviewDialerOfferException('dial_prefix_not_configured', 409);
            }

            // ── Shared outbound preparation (identical pipeline as Quick Call) ──
            // Creates Conversation + VoiceCall BEFORE the INVITE and builds the SIP headers,
            // so X-xedik-Conversation-Id travels in the outbound INVITE for Preview exactly
            // as it does for Manual Dial.
            $core = $this->outboundPreparation->prepare($user, [
                'campaign'     => $crmCampaign,
                'queue'        => $queue,
                'script'       => $script,
                'clean_phone'  => $this->cleanPreviewPhone((string) $offer->phone),
                'caller_id'    => [
                    'number'             => $callerId?->number,
                    'voice_caller_id_id' => $callerId?->id,
                    'source'             => $callerId ? 'preview_campaign' : 'endpoint_default',
                ],
                'dial_prefix'  => $dialPrefix,
                'call_mode'    => OutboundCallPreparationService::CALL_MODE_PREVIEW,
                'call_uuid'    => $callUuid,
                'session_uuid' => null,
            ]);

            $conversation = $core['conversation'];
            $voiceCall    = $core['voice_call'];

            $offer->update([
                'status'             => 'accepted',
                'accepted_at'        => now(),
                'call_uuid'          => $core['call_uuid'],
                'voice_caller_id_id' => $callerId?->id,
                'dial_prefix'        => $dialPrefix,
                'conversation_id'    => (int) $conversation->id,
            ]);

            // Stamp the Preview metadata onto the freshly created Conversation, matching
            // what linkConversation() would persist. Keeps a subsequent link call a no-op.
            $this->syncOfferMetadataToConversation(
                $offer->fresh(['dialerCampaign']),
                (int) $conversation->id
            );

            $fresh = $offer->fresh(['dialerCampaign', 'leadList', 'voiceCallerId']);
            $fresh->callPreparation = [
                'voice_call_id'    => (int) $voiceCall->id,
                'caller_id_number' => $callerId?->number,
                'queue_name'       => $core['queue_name'],
                'extra_headers'    => $core['extra_headers'],
            ];

            return $fresh;
        });
    }

    /**
     * The CRM Campaign backing this Preview Dialer campaign. Cross-tenant guarded and must
     * be active — a paused/deleted campaign must not originate Preview calls.
     */
    private function resolvePreviewCrmCampaign(DialerCampaign $campaign): Campaign
    {
        $crmCampaign = Campaign::withoutGlobalScopes()
            ->where('id', (int) $campaign->campaign_id)
            ->where('tenant_id', (int) $campaign->tenant_id)
            ->where('is_active', true)
            ->first();

        if (!$crmCampaign) {
            throw new PreviewDialerOfferException('campaign_not_configured', 409);
        }

        return $crmCampaign;
    }

    /**
     * Operational Voice Queue for this Preview Dialer campaign (dialer_campaigns.omni_queue_id).
     * Must belong to the same tenant and CRM campaign, be voice-eligible (active, type
     * voice/omni, enabled Voice setting with a non-empty asterisk_queue_name). It supplies
     * asterisk_queue_name for reporting; it does NOT distribute the call.
     */
    private function resolvePreviewQueue(DialerCampaign $campaign, Campaign $crmCampaign): OmniQueue
    {
        if (!$campaign->omni_queue_id) {
            throw new PreviewDialerOfferException('queue_not_configured', 409);
        }

        $queue = OmniQueue::withoutGlobalScopes()
            ->voiceEligible()
            ->where('id', (int) $campaign->omni_queue_id)
            ->where('tenant_id', (int) $campaign->tenant_id)
            ->where('campaign_id', (int) $crmCampaign->id)
            ->with('voiceSettings')
            ->first();

        if (!$queue) {
            throw new PreviewDialerOfferException('queue_not_eligible', 409);
        }

        return $queue;
    }

    /**
     * The outbound/both Script for this Preview Dialer campaign. Same tenant + CRM campaign,
     * active. Preview eligibility already validated this at claim time; re-checked here so
     * accept never proceeds on a script that changed underneath the offer.
     */
    private function resolvePreviewScript(DialerCampaign $campaign, Campaign $crmCampaign): Script
    {
        $script = Script::withoutGlobalScopes()
            ->where('id', (int) $campaign->script_id)
            ->where('tenant_id', (int) $campaign->tenant_id)
            ->where('campaign_id', (int) $crmCampaign->id)
            ->where('is_active', true)
            ->whereIn('direction', ['outbound', 'both'])
            ->first();

        if (!$script) {
            throw new PreviewDialerOfferException('script_not_configured', 409);
        }

        return $script;
    }

    private function cleanPreviewPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public function linkConversation(User $user, string $token, int $conversationId): PreviewDialerOffer
    {
        return DB::transaction(function () use ($user, $token, $conversationId): PreviewDialerOffer {
            $offer = $this->offerForUserByTokenForUpdate($user, $token);

            if ($offer->status !== 'accepted') {
                throw new PreviewDialerOfferException('offer_not_accepted', 409);
            }

            if ($offer->conversation_id !== null) {
                $this->syncOfferMetadataToConversation($offer, (int) $offer->conversation_id);
                return $offer->fresh(['dialerCampaign', 'leadList']);
            }

            $offer->update(['conversation_id' => $conversationId]);
            $this->syncOfferMetadataToConversation($offer->fresh(['dialerCampaign']), $conversationId);

            return $offer->fresh(['dialerCampaign', 'leadList']);
        });
    }

    private function syncOfferMetadataToConversation(PreviewDialerOffer $offer, int $conversationId): void
    {
        $campaign = DialerCampaign::withoutGlobalScopes()
            ->find((int) $offer->dialer_campaign_id);

        Conversation::withoutGlobalScopes()
            ->where('tenant_id', (int) $offer->tenant_id)
            ->where('id', $conversationId)
            ->update([
                'dialer_campaign_id' => (int) $offer->dialer_campaign_id,
                'lead_list_id' => (int) $offer->lead_list_id,
                'lead_list_row_id' => (int) $offer->lead_list_row_id,
                'campaign_id' => $campaign?->campaign_id,
                'script_id' => $campaign?->script_id,
            ]);
    }

    /**
     * @return array{offer: ?PreviewDialerOffer, voice_call: ?VoiceCall, is_voice_wrap_up: bool, recovered: bool, reason: ?string}
     */
    public function recoverStaleCallAfterRefresh(User $user, string $token, int $conversationId): array
    {
        $offer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['dialerCampaign', 'leadList'])
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('reservation_token', $token)
            ->first();

        if (!$offer) {
            throw new PreviewDialerOfferException('offer_not_found', 404);
        }

        if ($offer->status !== 'accepted' || (int) $offer->active_slot !== 1) {
            return $this->staleCallRecoveryResult($offer, null, false, 'offer_not_active');
        }

        if (!$offer->call_uuid || (int) $offer->conversation_id !== $conversationId) {
            return $this->staleCallRecoveryResult($offer, null, false, 'conversation_mismatch');
        }

        $call = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', (int) $offer->tenant_id)
            ->where('conversation_id', $conversationId)
            ->where('call_uuid', $offer->call_uuid)
            ->first();

        if (!$call) {
            return $this->staleCallRecoveryResult($offer, null, false, 'voice_call_not_found');
        }

        $recovered = $this->lifecycleService->recoverStaleUnansweredCall($offer, $call);
        $freshOffer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['dialerCampaign', 'leadList'])
            ->find($offer->id);
        $freshCall = VoiceCall::withoutGlobalScopes()->find($call->id);

        return $this->staleCallRecoveryResult($freshOffer, $freshCall, $recovered, $recovered ? null : 'not_recoverable');
    }

    /**
     * @return array{offer: ?PreviewDialerOffer, voice_call: ?VoiceCall, is_voice_wrap_up: bool, recovered: bool, reason: ?string}
     */
    private function staleCallRecoveryResult(?PreviewDialerOffer $offer, ?VoiceCall $call, bool $recovered, ?string $reason): array
    {
        $conversation = $call?->conversation()
            ->with('latestVoiceCall')
            ->first();

        return [
            'offer' => $offer,
            'voice_call' => $call,
            'is_voice_wrap_up' => $conversation ? $conversation->isVoiceWrapUpPending() : false,
            'recovered' => $recovered,
            'reason' => $reason,
        ];
    }

    public function decline(User $user, string $token): PreviewDialerOffer
    {
        return DB::transaction(function () use ($user, $token): PreviewDialerOffer {
            $offer = $this->offerForUserByTokenForUpdate($user, $token);

            if ($offer->status === 'declined') {
                return $offer;
            }

            if ($offer->status !== 'pending') {
                throw new PreviewDialerOfferException('invalid_offer_status', 409);
            }

            // Decline is a terminal offer state: finalize it consistently (declined_at +
            // finalized_at). Reservation release, reserved_by clearing, no called_count
            // change and no lead completion are handled by releaseLeadReservation().
            $offer->update([
                'status' => 'declined',
                'active_slot' => null,
                'declined_at' => $offer->declined_at ?? now(),
                'finalized_at' => $offer->finalized_at ?? now(),
            ]);

            $this->releaseLeadReservation($offer, updateLastOffered: true);

            return $offer->fresh(['dialerCampaign', 'leadList']);
        });
    }

    public function expire(User $user, string $token): PreviewDialerOffer
    {
        return DB::transaction(function () use ($user, $token): PreviewDialerOffer {
            $offer = $this->offerForUserByTokenForUpdate($user, $token);

            if ($offer->status === 'expired') {
                return $offer;
            }

            if (!in_array($offer->status, ['pending', 'accepted'], true)) {
                throw new PreviewDialerOfferException('invalid_offer_status', 409);
            }

            $this->expireOffer($offer);

            return $offer->fresh(['dialerCampaign', 'leadList']);
        });
    }

    public function serializeOffer(PreviewDialerOffer $offer): array
    {
        $offer->loadMissing('voiceCallerId');
        $serverNow = Carbon::now();

        $leadList = $offer->leadList;
        $lead = $leadList
            ? DB::table($leadList->table_name)->where('id', (int) $offer->lead_list_row_id)->first()
            : null;

        $script = $offer->dialerCampaign?->script_id
            ? Script::withoutGlobalScopes()->find($offer->dialerCampaign->script_id)
            : null;

        $timeoutSeconds = (int) ($offer->dialerCampaign?->offer_timeout_seconds ?? 0);
        if ($timeoutSeconds <= 0 && $offer->offered_at && $offer->expires_at) {
            $timeoutSeconds = max(0, (int) ceil(
                (float) $offer->expires_at->format('U.u') - (float) $offer->offered_at->format('U.u')
            ));
        }

        $remainingSeconds = 0;
        if ($offer->expires_at) {
            $remainingSeconds = max(0, (int) ceil(
                (float) $offer->expires_at->format('U.u') - (float) $serverNow->format('U.u')
            ));
            if ($timeoutSeconds > 0) {
                $remainingSeconds = min($remainingSeconds, $timeoutSeconds);
            }
        }

        return [
            'id'              => $offer->id,
            'token'           => $offer->reservation_token,
            'status'          => $offer->status,
            'active_slot'     => $offer->active_slot,
            'call_uuid'       => $offer->call_uuid,
            'conversation_id' => $offer->conversation_id,
            // Present only on the accept() response: the backend-prepared VoiceCall id, the
            // technical queue name, and the SIP INVITE headers the frontend forwards verbatim.
            'voice_call_id'   => $offer->callPreparation['voice_call_id'] ?? null,
            'queue_name'      => $offer->callPreparation['queue_name'] ?? null,
            'extra_headers'   => $offer->callPreparation['extra_headers'] ?? null,
            'offered_at'      => optional($offer->offered_at)->toIso8601String(),
            'expires_at'      => optional($offer->expires_at)->toIso8601String(),
            'timeout_seconds' => $timeoutSeconds,
            'remaining_seconds' => $remainingSeconds,
            'server_now'      => $serverNow->toIso8601String(),
            'accepted_at'     => optional($offer->accepted_at)->toIso8601String(),
            'campaign' => [
                'id'             => $offer->dialerCampaign?->id,
                'name'           => $offer->dialerCampaign?->name,
                'crm_campaign_id' => $offer->dialerCampaign?->campaign_id,
            ],
            'lead_list' => [
                'id'   => $leadList?->id,
                'name' => $leadList?->name,
            ],
            'lead' => [
                'row_id'        => (int) $offer->lead_list_row_id,
                'phone'         => $offer->phone,
                'custom_fields' => $lead && $lead->custom_fields ? json_decode((string) $lead->custom_fields, true) : null,
            ],
            'script' => [
                'id'        => $script?->id,
                'name'      => $script?->name,
                'type'      => $script?->type,
                'direction' => $script?->direction,
            ],
            'caller_id' => $offer->voiceCallerId ? [
                'id'     => $offer->voiceCallerId->id,
                'number' => $offer->voiceCallerId->number,
                'name'   => $offer->voiceCallerId->name,
            ] : null,
            'dial_prefix' => $offer->dial_prefix,
        ];
    }

    private function tryAutoCompleteIfExhausted(DialerCampaign $campaign): void
    {
        // Leads may be temporarily locked by active offers; check before completing.
        $hasActiveOffers = PreviewDialerOffer::withoutGlobalScopes()
            ->where('dialer_campaign_id', (int) $campaign->id)
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();

        if ($hasActiveOffers) {
            return;
        }

        // Phase 1: leads parked with a future callback_at (all-declined hold, or scheduled
        // callbacks) are not exhausted — they re-enter the eligible pool when due. Do not
        // complete the campaign while such work is still pending, or the held lead would be
        // stranded and could never start a new round.
        if ($this->hasFutureCallbackLeads($campaign)) {
            return;
        }

        $campaign->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        Log::info("PreviewDialerOfferService: campaign #{$campaign->id} auto-completed — no eligible leads remain.", [
            'tenant_id'          => $campaign->tenant_id,
            'dialer_campaign_id' => $campaign->id,
            'lead_list_id'       => $campaign->lead_list_id,
        ]);
    }

    /**
     * Are there leads parked with a future callback_at that will become eligible again?
     * Used to keep a campaign running while an all-declined hold (or a scheduled callback)
     * is pending, so the held lead is not stranded by auto-completion.
     */
    private function hasFutureCallbackLeads(DialerCampaign $campaign): bool
    {
        $leadList = $campaign->leadList;
        if (!$leadList) {
            return false;
        }

        return DB::table($leadList->table_name)
            ->whereIn('status', self::ELIGIBLE_LEAD_STATUSES)
            ->where('called_count', '<', (int) $campaign->max_retries)
            ->whereNull('reserved_by')
            ->whereNotNull('callback_at')
            ->where('callback_at', '>', now())
            ->exists();
    }

    private function agentIsReady(User $user): bool
    {
        $session = AgentSession::withoutGlobalScopes()
            ->where('user_id', (int) $user->id)
            ->first();

        return $session?->isReady() === true;
    }

    /**
     * SQL equivalent of Conversation::isVoiceWrapUpPending() scoped to this agent.
     * Uses a latest VoiceCall EXISTS to avoid loading conversations or call histories.
     */
    private function agentIsInVoiceWrapUp(User $user): bool
    {
        return DB::table('conversations')
            ->where('assigned_to', (int) $user->id)
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('channel', 'voice')
            ->where('status', 'active')
            ->whereNull('disposition')
            ->whereNull('resolved_at')
            ->whereExists(function ($query) use ($user): void {
                $query->selectRaw('1')
                    ->from('voice_calls as latest_voice_calls')
                    ->whereColumn('latest_voice_calls.conversation_id', 'conversations.id')
                    ->where('latest_voice_calls.tenant_id', (int) $user->tenant_id)
                    ->whereIn('latest_voice_calls.call_state', VoiceCall::TERMINAL_STATES)
                    ->whereNotNull('latest_voice_calls.answered_at')
                    ->whereNotExists(function ($newer): void {
                        $newer->selectRaw('1')
                            ->from('voice_calls as newer_voice_calls')
                            ->whereColumn('newer_voice_calls.conversation_id', 'latest_voice_calls.conversation_id')
                            ->where(function ($latest): void {
                                $latest->whereColumn('newer_voice_calls.created_at', '>', 'latest_voice_calls.created_at')
                                    ->orWhere(function ($tie): void {
                                        $tie->whereColumn('newer_voice_calls.created_at', 'latest_voice_calls.created_at')
                                            ->whereColumn('newer_voice_calls.id', '>', 'latest_voice_calls.id');
                                    });
                            });
                    });
            })
            ->exists();
    }

    private function opportunisticallyExpirePendingOffer(User $user): void
    {
        $offer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['leadList'])
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('active_slot', 1)
            ->where('status', 'pending')
            ->first();

        if ($offer) {
            $this->expireOffer($offer);
        }
    }

    private function previewEnabled(int $tenantId): bool
    {
        return (bool) DB::table('voice_tenant_settings')
            ->where('tenant_id', $tenantId)
            ->value('preview_dialer_enabled');
    }

    private function runningPreviewCampaigns(int $tenantId)
    {
        return DialerCampaign::withoutGlobalScopes()
            ->with(['campaign.users', 'leadList', 'script'])
            ->where('tenant_id', $tenantId)
            ->where('dialer_type', 'preview')
            ->where('status', 'running')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    private function eligibleCampaignsForUser(User $user)
    {
        return $this->runningPreviewCampaigns((int) $user->tenant_id)
            ->filter(function (DialerCampaign $campaign) use ($user): bool {
                return $campaign->campaign
                    && $campaign->campaign->is_active
                    && $campaign->campaign->users->contains('id', (int) $user->id);
            })
            ->values();
    }

    private function campaignConfigurationIsValid(DialerCampaign $campaign): bool
    {
        return $campaign->leadList
            && (int) $campaign->leadList->tenant_id === (int) $campaign->tenant_id
            && $this->tableService->isPreviewSchemaPrepared($campaign->leadList)
            && $campaign->script
            && (int) $campaign->script->tenant_id === (int) $campaign->tenant_id
            && (int) $campaign->script->campaign_id === (int) $campaign->campaign_id
            && $campaign->script->is_active
            && in_array($campaign->script->direction, ['outbound', 'both'], true);
    }

    /**
     * @return array{lead: ?object, blocked_by_calling_hours: bool, skipped_for_fairness: bool}
     */
    private function nextEligibleLead(DialerCampaign $campaign, User $user, bool $lockForUpdate = false): array
    {
        $dncPhones = DB::table('dialer_dnc')
            ->where('tenant_id', (int) $campaign->tenant_id)
            ->pluck('phone')
            ->all();

        $filterConfig = $this->filterService->normalize($campaign->filter_config);
        $callingHoursEnabled = $this->complianceService->isEnabled($campaign);
        $blockedByCallingHours = false;
        // True when at least one otherwise-eligible lead existed but the fairness guard
        // withheld it from THIS agent (declined-this-round / all-declined hold). Such a lead
        // is still workable by another agent, so the caller must NOT treat a null result as
        // campaign exhaustion and auto-complete.
        $skippedForFairness = false;

        foreach ([true, false] as $excludeLastOfferedToCurrentUser) {
            $query = $this->filterService->apply(
                $this->leadQuery($campaign, $user, $dncPhones, $excludeLastOfferedToCurrentUser),
                $campaign,
                $filterConfig
            );

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            if (!$callingHoursEnabled) {
                // Scan candidates in the existing leadQuery() order and return the first one
                // that the fairness guard allows for THIS agent. Ordering/filters are
                // unchanged; we only skip a lead this agent already declined this round while
                // another eligible agent has not tried it (decline rotation).
                foreach ($query->limit(self::FAIRNESS_SCAN_LIMIT)->get() as $candidate) {
                    if ($this->candidateAssignableToAgent($campaign, $candidate, $user)) {
                        return ['lead' => $candidate, 'blocked_by_calling_hours' => false, 'skipped_for_fairness' => false];
                    }
                    $skippedForFairness = true;
                }
                continue;
            }

            foreach ($query->limit(self::CALLING_HOURS_SCAN_LIMIT)->get() as $candidate) {
                $decision = $this->complianceService->evaluate($campaign, (string) $candidate->phone);
                if (!$decision['allowed']) {
                    $blockedByCallingHours = true;
                    continue;
                }
                if ($this->candidateAssignableToAgent($campaign, $candidate, $user)) {
                    return ['lead' => $candidate, 'blocked_by_calling_hours' => false, 'skipped_for_fairness' => false];
                }
                $skippedForFairness = true;
            }
        }

        return [
            'lead' => null,
            'blocked_by_calling_hours' => $blockedByCallingHours,
            'skipped_for_fairness' => $skippedForFairness,
        ];
    }

    /**
     * Phase 1 Fair Pull Distribution — decline-rotation guard.
     *
     * Decides whether an already-eligible candidate lead (it already passed every existing
     * leadQuery() filter and is next in the existing order) may be offered to THIS agent
     * right now. This ONLY governs offer assignment fairness; it never changes which leads
     * are eligible or their order.
     *
     * Rules ("tried" = declined OR expired this round):
     *   1. Fresh for this agent (not tried this round) -> assignable.
     *   2. Tried by this agent this round, but another eligible agent has not tried it
     *      -> not assignable to this agent (let the other agent claim it). No side effect.
     *   3. Tried by this agent this round and no other eligible untried agent exists
     *      -> hold the lead (callback_at = now()+10m) so it leaves the pool instead of
     *         looping back to the same agent, then treat as not assignable.
     */
    private function candidateAssignableToAgent(DialerCampaign $campaign, object $lead, User $user): bool
    {
        $leadListId = (int) $campaign->lead_list_id;
        $leadRowId = (int) $lead->id;
        // The lead's callback_at is the inferred round boundary: tries recorded before it
        // (e.g. from a previous all-declined hold) belong to a prior round and are ignored.
        $roundBoundary = $lead->callback_at ?? null;

        if (!$this->agentTriedLeadThisRound($campaign, $leadListId, $leadRowId, (int) $user->id, $roundBoundary)) {
            return true;
        }

        if ($this->otherEligibleUntriedAgentExists($campaign, $leadListId, $leadRowId, $user, $roundBoundary)) {
            return false;
        }

        // Every currently-eligible agent (including this one) has already tried the lead
        // (declined/expired) this round — park it so it does not loop straight back to the
        // requesting agent.
        $this->holdLeadAfterAllDeclined($campaign, $lead);

        return false;
    }

    /**
     * Has $userId already TRIED this specific lead within the current inferred round?
     * A try is a terminal offer the agent did not act on: declined OR expired (timeout).
     * Both mean "the agent had their turn and no call was placed", so for fairness they
     * rotate the lead away from that agent identically. Uses only existing
     * preview_dialer_offers history (no schema change).
     *
     * $roundBoundary is the lead's callback_at: a try counts for the current round only when
     * its terminal timestamp is after that boundary (tries before a previous all-declined
     * hold belong to a prior round). finalized_at is set for both declined and expired going
     * forward; declined_at/expired_at are also checked so pre-existing rows still resolve.
     */
    private function agentTriedLeadThisRound(
        DialerCampaign $campaign,
        int $leadListId,
        int $leadRowId,
        int $userId,
        $roundBoundary
    ): bool {
        return PreviewDialerOffer::withoutGlobalScopes()
            ->where('dialer_campaign_id', (int) $campaign->id)
            ->where('lead_list_id', $leadListId)
            ->where('lead_list_row_id', $leadRowId)
            ->where('user_id', $userId)
            ->whereIn('status', ['declined', 'expired'])
            ->when($roundBoundary !== null, function ($query) use ($roundBoundary): void {
                $query->where(function ($inner) use ($roundBoundary): void {
                    $inner->where('finalized_at', '>', $roundBoundary)
                        ->orWhere('declined_at', '>', $roundBoundary)
                        ->orWhere('expired_at', '>', $roundBoundary);
                });
            })
            ->exists();
    }

    /**
     * Is there another agent — not the requester — who could take this lead right now:
     * assigned to the campaign, ready, not in voice wrap-up, with no active Preview offer,
     * and who has NOT tried this lead this round (declined/expired)? Reuses existing readiness.
     */
    private function otherEligibleUntriedAgentExists(
        DialerCampaign $campaign,
        int $leadListId,
        int $leadRowId,
        User $currentUser,
        $roundBoundary
    ): bool {
        foreach ($this->campaignAssignedAgents($campaign) as $agent) {
            if ((int) $agent->id === (int) $currentUser->id) {
                continue;
            }
            if ($this->agentHasActivePreviewOffer($agent)) {
                continue;
            }
            if (!$this->agentIsReady($agent)) {
                continue;
            }
            if ($this->agentIsInVoiceWrapUp($agent)) {
                continue;
            }
            if ($this->agentTriedLeadThisRound($campaign, $leadListId, $leadRowId, (int) $agent->id, $roundBoundary)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Active users assigned to the campaign's CRM campaign — the pool of agents who can
     * receive Preview offers for it. campaign.users is eager-loaded by
     * runningPreviewCampaigns(); this reuses it rather than inventing a status system.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function campaignAssignedAgents(DialerCampaign $campaign)
    {
        $crmCampaign = $campaign->campaign;
        if (!$crmCampaign) {
            return collect();
        }

        return $crmCampaign->users
            ->filter(fn ($agent): bool => (bool) $agent->is_active)
            ->values();
    }

    private function agentHasActivePreviewOffer(User $agent): bool
    {
        return PreviewDialerOffer::withoutGlobalScopes()
            ->where('tenant_id', (int) $agent->tenant_id)
            ->where('user_id', (int) $agent->id)
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * All-agents-declined hold. Parks the lead for ALL_DECLINED_HOLD_MINUTES via callback_at
     * so it leaves the eligible pool (leadQuery excludes future callback_at) and cannot loop
     * back to the same agent. Never touches called_count, status, disposition, or reserved_by
     * — this is purely a temporary availability hold using an existing field.
     */
    private function holdLeadAfterAllDeclined(DialerCampaign $campaign, object $lead): void
    {
        DB::table($campaign->leadList->table_name)
            ->where('id', (int) $lead->id)
            ->whereNull('reserved_by')
            ->update([
                'callback_at' => now()->addMinutes(self::ALL_DECLINED_HOLD_MINUTES),
            ]);
    }

    private function leadQuery(DialerCampaign $campaign, User $user, array $dncPhones, bool $excludeLastOfferedToCurrentUser)
    {
        $leadList = $campaign->leadList;
        $direction = $campaign->lead_order_direction === 'desc' ? 'desc' : 'asc';

        return DB::table($leadList->table_name)
            ->whereIn('status', self::ELIGIBLE_LEAD_STATUSES)
            ->where('called_count', '<', (int) $campaign->max_retries)
            ->whereNull('reserved_by')
            ->where(function ($q): void {
                $q->whereNull('callback_at')->orWhere('callback_at', '<=', now());
            })
            ->when($excludeLastOfferedToCurrentUser, function ($query) use ($user) {
                $query->where(function ($nested) use ($user) {
                    $nested->whereNull('last_offered_to')
                        ->orWhere('last_offered_to', '<>', (int) $user->id);
                });
            })
            ->when($dncPhones !== [], fn ($query) => $query->whereNotIn('phone', $dncPhones))
            ->whereNotExists(function ($query) use ($leadList) {
                $query->selectRaw('1')
                    ->from('preview_dialer_offers')
                    ->whereColumn('preview_dialer_offers.lead_list_row_id', "{$leadList->table_name}.id")
                    ->where('preview_dialer_offers.lead_list_id', (int) $leadList->id)
                    ->where('preview_dialer_offers.active_slot', 1)
                    ->whereIn('preview_dialer_offers.status', self::ACTIVE_STATUSES);
            })
            ->orderByRaw('CASE WHEN callback_at IS NOT NULL AND callback_at <= ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw('CASE WHEN callback_at IS NOT NULL AND callback_at <= ? THEN callback_at ELSE NULL END ASC', [now()])
            ->orderByRaw('CASE WHEN last_offered_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_offered_at')
            ->orderBy('id', $direction);
    }

    private function createOffer(DialerCampaign $campaign, User $user, object $lead): PreviewDialerOffer
    {
        $now = Carbon::now();

        return PreviewDialerOffer::withoutGlobalScopes()->create([
            'tenant_id' => (int) $campaign->tenant_id,
            'user_id' => (int) $user->id,
            'dialer_campaign_id' => (int) $campaign->id,
            'lead_list_id' => (int) $campaign->lead_list_id,
            'lead_list_row_id' => (int) $lead->id,
            'phone' => (string) $lead->phone,
            'reservation_token' => bin2hex(random_bytes(32)),
            'status' => 'pending',
            'active_slot' => 1,
            'offered_at' => $now,
            'expires_at' => $now->copy()->addSeconds((int) $campaign->offer_timeout_seconds),
        ]);
    }

    private function reserveLeadForOffer(DialerCampaign $campaign, User $user, object $lead): bool
    {
        $updated = DB::table($campaign->leadList->table_name)
            ->where('id', (int) $lead->id)
            ->whereIn('status', self::ELIGIBLE_LEAD_STATUSES)
            ->where('called_count', '<', (int) $campaign->max_retries)
            ->whereNull('reserved_by')
            ->where(function ($query): void {
                $query->whereNull('callback_at')->orWhere('callback_at', '<=', now());
            })
            ->whereNotExists(function ($query) use ($campaign): void {
                $query->selectRaw('1')
                    ->from('preview_dialer_offers')
                    ->whereColumn('preview_dialer_offers.lead_list_row_id', "{$campaign->leadList->table_name}.id")
                    ->where('preview_dialer_offers.lead_list_id', (int) $campaign->lead_list_id)
                    ->where('preview_dialer_offers.active_slot', 1)
                    ->whereIn('preview_dialer_offers.status', self::ACTIVE_STATUSES);
            })
            ->update([
                'reserved_by' => (int) $user->id,
                'last_offered_to' => (int) $user->id,
                'last_offered_at' => now(),
            ]);

        return $updated === 1;
    }

    private function releaseReservedCandidate(DialerCampaign $campaign, User $user, object $lead): void
    {
        DB::table($campaign->leadList->table_name)
            ->where('id', (int) $lead->id)
            ->where('reserved_by', (int) $user->id)
            ->update([
                'reserved_by' => null,
                'last_offered_to' => null,
                'last_offered_at' => null,
            ]);
    }

    private function isRetryableClaimConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($driverCode, [1062, 1205, 1213], true)
            || ($sqlState === '23000' && Str::contains(Str::lower($exception->getMessage()), 'unique'));
    }

    private function currentOfferForUpdate(User $user): ?PreviewDialerOffer
    {
        $offer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['leadList'])
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->first();

        if ($this->expireIfPendingOfferIsStale($offer)) {
            return null;
        }

        return $offer;
    }

    private function offerForUserByTokenForUpdate(User $user, string $token): PreviewDialerOffer
    {
        $offer = PreviewDialerOffer::withoutGlobalScopes()
            ->with(['dialerCampaign', 'leadList'])
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('reservation_token', $token)
            ->lockForUpdate()
            ->first();

        if (!$offer) {
            throw new PreviewDialerOfferException('offer_not_found', 404);
        }

        return $offer;
    }

    private function expireOffer(PreviewDialerOffer $offer): void
    {
        // Expire is a terminal offer state: finalize it consistently. expired_at and
        // finalized_at are set only when still null (idempotent). active_slot is cleared and
        // the lead reservation (reserved_by on the dynamic row) is released below. No
        // called_count change and no lead completion — no call was placed.
        $offer->update([
            'status' => 'expired',
            'active_slot' => null,
            'expired_at' => $offer->expired_at ?? now(),
            'finalized_at' => $offer->finalized_at ?? now(),
        ]);

        $this->releaseLeadReservation($offer, updateLastOffered: false);
    }

    private function expireIfPendingOfferIsStale(?PreviewDialerOffer $offer): bool
    {
        if (!$offer || $offer->status !== 'pending' || !$offer->expires_at || !$offer->expires_at->isPast()) {
            return false;
        }

        $this->expireOffer($offer);

        return true;
    }

    private function releaseLeadReservation(PreviewDialerOffer $offer, bool $updateLastOffered): void
    {
        $updates = ['reserved_by' => null];

        if ($updateLastOffered) {
            $updates['last_offered_to'] = (int) $offer->user_id;
            $updates['last_offered_at'] = now();
        }

        DB::table($offer->leadList->table_name)
            ->where('id', (int) $offer->lead_list_row_id)
            ->where('reserved_by', (int) $offer->user_id)
            ->update($updates);
    }

}
