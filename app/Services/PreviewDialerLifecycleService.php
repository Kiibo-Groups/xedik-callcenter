<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PreviewDialerOffer;
use App\Models\VoiceCall;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PreviewDialerLifecycleService
{
    /**
     * Count one attempt in the lead table and insert a log stub when JsSIP
     * fires "180 Ringing" (connecting → ringing transition).
     *
     * Idempotent: guarded by preview_attempt_counted_at on the VoiceCall and
     * the UNIQUE index on call_uuid in the log table.
     */
    public function countAttemptAtRinging(VoiceCall $call): void
    {
        if ($call->preview_attempt_counted_at !== null) {
            return;
        }

        DB::transaction(function () use ($call): void {
            // Re-read with lock to prevent concurrent double-count
            $fresh = VoiceCall::withoutGlobalScopes()
                ->where('id', $call->id)
                ->lockForUpdate()
                ->first();

            if (!$fresh || $fresh->preview_attempt_counted_at !== null) {
                return;
            }

            $offer = $this->findAcceptedOffer($fresh);
            if (!$offer) {
                return; // Not a Preview call
            }

            $leadList = $offer->leadList;
            if (!$leadList) {
                return;
            }

            DB::table($leadList->table_name)
                ->where('id', (int) $offer->lead_list_row_id)
                ->increment('called_count', 1, ['last_called_at' => now()]);

            DB::table($leadList->log_table_name)->insertOrIgnore([
                'dialer_campaign_id' => (int) $offer->dialer_campaign_id,
                'dialer_lead_id'     => (int) $offer->lead_list_row_id,
                'phone'              => $offer->phone,
                'uniqueid'           => null,
                'call_uuid'          => $fresh->call_uuid,
                'conversation_id'    => $fresh->conversation_id ?? $offer->conversation_id,
                'final_status'       => null,
                'disposition'        => null,
                'duration'           => 0,
                'billsec'            => 0,
                'called_at'          => now(),
            ]);

            $fresh->forceFill(['preview_attempt_counted_at' => now()])->save();

            // Propagate to the caller's reference
            $call->forceFill(['preview_attempt_counted_at' => $fresh->preview_attempt_counted_at]);
        });
    }

    /**
     * Finalize the Preview offer and update the lead row when the VoiceCall
     * reaches a terminal state.
     *
     * Idempotent: skips if the offer is already non-accepted.
     * Handles failed-before-ringing (upserts the log row even if the stub was
     * never created by countAttemptAtRinging).
     *
     * Offer status contract:
     *   completed = call reached ringing (cancelled/busy/no_answer/ended all qualify)
     *   failed    = INVITE never reached ringing, or stale offer with no VoiceCall
     *
     * Lead status (NEW/BUSY/etc.) is NOT changed here — no disposition policy approved yet.
     */
    public function finalizeAtEnd(VoiceCall $call): void
    {
        // Guard: if the call reached ringing but attempt wasn't counted yet
        // (e.g. watchdog path, or terminal PATCH arrived before connecting→ringing),
        // count the attempt now before finalizing.
        if ($call->ringing_at !== null && $call->preview_attempt_counted_at === null) {
            $this->countAttemptAtRinging($call);
        }

        DB::transaction(function () use ($call): void {
            $offer = PreviewDialerOffer::withoutGlobalScopes()
                ->with(['leadList'])
                ->where('tenant_id', $call->tenant_id)
                ->where('call_uuid', $call->call_uuid)
                ->where('status', 'accepted')
                ->lockForUpdate()
                ->first();

            if (!$offer) {
                return; // Not Preview, or already finalized by a prior run
            }

            $leadList = $offer->leadList;
            if (!$leadList) {
                return;
            }

            // completed = call reached ringing (real SIP 180 received)
            // failed    = INVITE never reached ringing (fast 4xx, NO_ROUTE_DESTINATION, etc.)
            $offerStatus = $call->ringing_at !== null ? 'completed' : 'failed';

            // Propagate conversation_id onto the offer idempotently.
            // This can be null when the call fails so fast that the POST response
            // has not yet round-tripped to set it (e.g. immediate NO_ROUTE_DESTINATION).
            $offerUpdates = [
                'status'       => $offerStatus,
                'active_slot'  => null,
                'finalized_at' => now(),
            ];
            if ($offer->conversation_id === null && $call->conversation_id !== null) {
                $offerUpdates['conversation_id'] = $call->conversation_id;
            }
            $offer->forceFill($offerUpdates)->save();

            $leadUpdates = [
                'reserved_by'    => null,
                'status'         => $this->mapVoiceCallToLeadStatus($call),
                'last_called_at' => $call->ended_at ?? now(),
                'last_duration'  => (int) ($call->duration_seconds ?? 0),
                'last_billsec'   => (int) ($call->talk_seconds ?? 0),
            ];

            DB::table($leadList->table_name)
                ->where('id', (int) $offer->lead_list_row_id)
                ->update($leadUpdates);

            // Only write/update the log row when the call reached real ringing.
            // Pre-ringing failures (ringing_at=null) must not generate a log entry:
            // they do not count as attempts and should not appear in call history.
            if ($call->ringing_at !== null) {
                $logFinalStatus = $this->callStateToLogStatus($call->call_state);
                DB::table($leadList->log_table_name)->updateOrInsert(
                    ['call_uuid' => $call->call_uuid],
                    [
                        'dialer_campaign_id' => (int) $offer->dialer_campaign_id,
                        'dialer_lead_id'     => (int) $offer->lead_list_row_id,
                        'phone'              => $offer->phone,
                        'conversation_id'    => $call->conversation_id ?? $offer->conversation_id,
                        'final_status'       => $logFinalStatus,
                        'duration'           => (int) ($call->duration_seconds ?? 0),
                        'billsec'            => (int) ($call->talk_seconds ?? 0),
                    ]
                );
            }
        });
    }

    /**
     * Finalize a stale accepted offer that has no associated VoiceCall
     * (the call was accepted but never dialed, e.g. browser closed).
     */
    public function finalizeStaleOffer(PreviewDialerOffer $offer): void
    {
        if ($offer->status !== 'accepted') {
            return;
        }

        DB::transaction(function () use ($offer): void {
            $locked = PreviewDialerOffer::withoutGlobalScopes()
                ->where('id', $offer->id)
                ->where('status', 'accepted')
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
            }

            $locked->forceFill([
                'status'       => 'failed',
                'active_slot'  => null,
                'finalized_at' => now(),
            ])->save();

            $leadList = $locked->leadList;
            if ($leadList) {
                DB::table($leadList->table_name)
                    ->where('id', (int) $locked->lead_list_row_id)
                    ->where('reserved_by', (int) $locked->user_id)
                    ->update(['reserved_by' => null]);
            }

            Log::info('Preview lifecycle: stale accepted offer finalized', [
                'offer_id'   => $locked->id,
                'tenant_id'  => $locked->tenant_id,
                'user_id'    => $locked->user_id,
                'call_uuid'  => $locked->call_uuid,
            ]);
        });
    }

    /**
     * Finalize an accepted Preview call that was left in an early active state
     * after the browser lost its in-memory JsSIP RTCSession during refresh.
     *
     * Strictly limited to unanswered ringing/connecting calls. Answered, active,
     * and held calls are never closed here.
     */
    public function recoverStaleUnansweredCall(PreviewDialerOffer $offer, VoiceCall $call): bool
    {
        if (!$this->canRecoverStaleUnansweredCall($offer, $call)) {
            return false;
        }

        DB::transaction(function () use ($offer, $call): void {
            $lockedOffer = PreviewDialerOffer::withoutGlobalScopes()
                ->where('id', $offer->id)
                ->where('tenant_id', $offer->tenant_id)
                ->where('status', 'accepted')
                ->where('active_slot', 1)
                ->whereNotNull('call_uuid')
                ->lockForUpdate()
                ->first();

            if (!$lockedOffer) {
                return;
            }

            $lockedCall = VoiceCall::withoutGlobalScopes()
                ->where('id', $call->id)
                ->where('tenant_id', $lockedOffer->tenant_id)
                ->where('call_uuid', $lockedOffer->call_uuid)
                ->whereIn('call_state', ['ringing', 'connecting'])
                ->whereNull('answered_at')
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if (!$lockedCall) {
                return;
            }

            $endedAt = now();
            $updates = [
                'call_state'   => 'cancelled',
                'ended_at'     => $endedAt,
                'hangup_cause' => 'BROWSER_REFRESH_STALE_RINGING',
            ];

            if ($lockedCall->ringing_at) {
                $updates['duration_seconds'] = max(0, $endedAt->timestamp - $lockedCall->ringing_at->timestamp);
            }

            $lockedCall->forceFill($updates)->save();
        });

        $freshCall = VoiceCall::withoutGlobalScopes()->find($call->id);
        if (!$freshCall || $freshCall->call_state !== 'cancelled') {
            return false;
        }

        $this->finalizeAtEnd($freshCall);

        Log::info('Preview lifecycle: stale unanswered call recovered', [
            'offer_id'  => $offer->id,
            'tenant_id' => $offer->tenant_id,
            'user_id'   => $offer->user_id,
            'call_uuid' => $offer->call_uuid,
        ]);

        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function findAcceptedOffer(VoiceCall $call): ?PreviewDialerOffer
    {
        return PreviewDialerOffer::withoutGlobalScopes()
            ->with(['leadList'])
            ->where('tenant_id', $call->tenant_id)
            ->where('call_uuid', $call->call_uuid)
            ->where('status', 'accepted')
            ->first();
    }

    private function canRecoverStaleUnansweredCall(PreviewDialerOffer $offer, VoiceCall $call): bool
    {
        return $offer->status === 'accepted'
            && (int) $offer->active_slot === 1
            && $offer->call_uuid !== null
            && (int) $offer->tenant_id === (int) $call->tenant_id
            && hash_equals((string) $offer->call_uuid, (string) $call->call_uuid)
            && $call->ended_at === null
            && $call->answered_at === null
            && in_array($call->call_state, ['ringing', 'connecting'], true);
    }

    /**
     * Map the terminal VoiceCall state to the operational lead status.
     *
     * Rules:
     *  - ended + answered_at → ANSWERED  (call was completed normally)
     *  - ended without answer → NO_ANSWER (edge case)
     *  - cancelled + ringing_at set → NO_ANSWER (remote rang, agent/system cancelled before answer)
     *  - cancelled + no ringing_at   → FAILED   (INVITE cancelled before ring)
     *  - busy, no_answer, missed → mapped to semantic equivalent
     *  - congestion, rejected → distinct status for retry policy
     *  - failed, unavailable, unknown → FAILED
     */
    private function mapVoiceCallToLeadStatus(VoiceCall $call): string
    {
        return match(true) {
            $call->call_state === 'ended' && $call->answered_at !== null      => 'ANSWER',
            $call->call_state === 'ended'                                      => 'NO_ANSWER',
            $call->call_state === 'busy'                                       => 'BUSY',
            in_array($call->call_state, ['no_answer', 'missed'], true)        => 'NO_ANSWER',
            $call->call_state === 'cancelled' && $call->ringing_at !== null   => 'NO_ANSWER',
            $call->call_state === 'cancelled'                                  => 'FAILED',
            $call->call_state === 'congestion'                                 => 'FAILED',
            $call->call_state === 'rejected'                                   => 'FAILED',
            default                                                            => 'FAILED',
        };
    }

    /**
     * Map VoiceCall.call_state to the log's final_status value.
     *
     * Precedence rule: call_state is the normalized source of truth.
     * hangup_cause is not used here — call_state already encodes the
     * SIP outcome at the right granularity for reporting.
     *
     * Values:  ANSWER | NO_ANSWER | BUSY | CONGESTION | REJECTED | FAILED
     */
    private function callStateToLogStatus(string $callState): string
    {
        return match ($callState) {
            'ended'                            => 'ANSWER',
            'busy'                             => 'BUSY',
            'no_answer', 'missed', 'cancelled' => 'NO_ANSWER',
            'congestion'                       => 'CONGESTION',
            'rejected'                         => 'REJECTED',
            default                            => 'FAILED',
        };
    }
}
