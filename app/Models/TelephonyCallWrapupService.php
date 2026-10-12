<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Conversation;
use App\Models\TelephonyCall;
use App\Models\User;
use App\Models\VoiceCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class TelephonyCallWrapupService
{
    public function startFromVoiceCall(VoiceCall $voiceCall, ?CarbonInterface $startedAt = null): void
    {
        $startedAt ??= now();

        // Canonical CRM store: always record wrap-up start on the VoiceCall itself,
        // even when asterisk_linkedid is not known yet (outbound Manual Dial gets it
        // later via QueueLog). This is never lost and is reconciled into
        // telephony_calls when the linkedid arrives.
        if ($voiceCall->wrapup_started_at === null) {
            $voiceCall->forceFill(['wrapup_started_at' => $startedAt])->save();
        }

        $linkedid = $this->linkedidFromVoiceCall($voiceCall);
        if ($linkedid === null) {
            // Not a failure — the wrap-up start lives on the VoiceCall; QueueLog
            // reconciliation will carry it into telephony_calls. Diagnostic only.
            $this->warnMissingCorrelation('wrapup_start_missing_linkedid', [
                'voice_call_id' => $voiceCall->id,
                'tenant_id' => $voiceCall->tenant_id,
            ]);
            return;
        }

        $pbxId = $this->pbxId();
        if ($pbxId === null) {
            $this->warnMissingCorrelation('wrapup_start_missing_pbx_id', [
                'linkedid' => $linkedid,
                'voice_call_id' => $voiceCall->id,
                'tenant_id' => $voiceCall->tenant_id,
            ]);
            return;
        }

        $call = $this->firstOrCreateCall($pbxId, $linkedid, (int) $voiceCall->tenant_id);
        if (!$call) {
            return;
        }

        $updates = [];
        $userId = $this->trustedUserId($voiceCall->agent_user_id, (int) $voiceCall->tenant_id);

        if ($call->user_id === null && $userId !== null) {
            $updates['user_id'] = $userId;
        }

        if ($call->wrapup_started_at === null) {
            $updates['wrapup_started_at'] = $startedAt;
        }

        if ($updates !== []) {
            $call->forceFill($updates)->save();
        }
    }

    public function finishFromConversation(
        Conversation $conversation,
        string $disposition,
        ?CarbonInterface $endedAt = null
    ): void {
        $voiceCall = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();

        if (!$voiceCall) {
            $this->warnMissingCorrelation('wrapup_finish_missing_voice_call', [
                'conversation_id' => $conversation->id,
                'tenant_id' => $conversation->tenant_id,
            ]);
            return;
        }

        // Canonical CRM store: always record wrap-up end + seconds on the VoiceCall,
        // even without a linkedid yet. disposition itself already lives on the
        // Conversation (set by the caller before this runs), so it is never lost.
        $ended = $endedAt ?? now();
        $voiceUpdates = [];
        if ($voiceCall->wrapup_ended_at === null) {
            $voiceUpdates['wrapup_ended_at'] = $ended;
            if ($voiceCall->wrapup_started_at !== null) {
                $voiceUpdates['wrapup_seconds'] = max(
                    0,
                    (int) $voiceCall->wrapup_started_at->diffInSeconds($ended, false)
                );
            }
        }
        if ($voiceUpdates !== []) {
            $voiceCall->forceFill($voiceUpdates)->save();
        }

        $linkedid = $this->linkedidFromVoiceCall($voiceCall);
        if ($linkedid === null) {
            // Not a failure — disposition is on the Conversation and wrap-up timing on
            // the VoiceCall; QueueLog reconciliation carries both into telephony_calls.
            $this->warnMissingCorrelation('wrapup_finish_missing_linkedid', [
                'conversation_id' => $conversation->id,
                'voice_call_id' => $voiceCall->id,
                'tenant_id' => $conversation->tenant_id,
            ]);
            return;
        }

        $pbxId = $this->pbxId();
        if ($pbxId === null) {
            $this->warnMissingCorrelation('wrapup_finish_missing_pbx_id', [
                'linkedid' => $linkedid,
                'conversation_id' => $conversation->id,
                'voice_call_id' => $voiceCall->id,
                'tenant_id' => $conversation->tenant_id,
            ]);
            return;
        }

        $call = $this->firstOrCreateCall($pbxId, $linkedid, (int) $conversation->tenant_id);
        if (!$call) {
            return;
        }

        $updates = ['disposition' => $disposition];
        $userId = $this->trustedUserId(
            $voiceCall->agent_user_id ?: $conversation->assigned_to,
            (int) $conversation->tenant_id
        );

        if ($call->user_id === null && $userId !== null) {
            $updates['user_id'] = $userId;
        }

        if ($call->wrapup_ended_at === null) {
            $ended = $endedAt ?? now();
            $updates['wrapup_ended_at'] = $ended;

            if ($call->wrapup_started_at !== null) {
                $updates['wrapup_seconds'] = max(
                    0,
                    (int) $call->wrapup_started_at->diffInSeconds($ended, false)
                );
            }
        }

        $call->forceFill($updates)->save();
    }

    private function firstOrCreateCall(int $pbxId, string $linkedid, int $tenantId): ?TelephonyCall
    {
        $call = TelephonyCall::withoutGlobalScopes()
            ->firstOrCreate(
                [
                    'pbx_id' => $pbxId,
                    'linkedid' => $linkedid,
                ],
                [
                    'tenant_id' => $tenantId,
                ]
            );

        if ((int) $call->tenant_id !== $tenantId) {
            Log::error('[telephony][wrapup] tenant_mismatch_for_linkedid', [
                'pbx_id' => $pbxId,
                'linkedid' => $linkedid,
                'expected_tenant_id' => $tenantId,
                'found_tenant_id' => (int) $call->tenant_id,
                'telephony_call_id' => $call->id,
            ]);

            return null;
        }

        return $call;
    }

    private function trustedUserId(mixed $userId, int $tenantId): ?int
    {
        if ($userId === null || $userId === '' || !is_numeric($userId)) {
            return null;
        }

        $userId = (int) $userId;
        if ($userId < 1) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->exists()
            ? $userId
            : null;
    }

    private function linkedidFromVoiceCall(VoiceCall $voiceCall): ?string
    {
        $linkedid = trim((string) ($voiceCall->asterisk_linkedid ?? ''));

        return $linkedid === '' ? null : $linkedid;
    }

    private function pbxId(): ?int
    {
        $value = config('voice.pbx_id');

        if ($value === null || $value === '' || !is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $context */
    private function warnMissingCorrelation(string $message, array $context): void
    {
        Log::warning('[telephony][wrapup] ' . $message, $context);
    }
}
