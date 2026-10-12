<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\User;
use App\Services\AgentStatusService;
use Illuminate\Validation\ValidationException;

/**
 * Shared outbound-call preparation pipeline.
 *
 * Manual Dial (Quick Call) and Preview Dialer are just two entry points into the same
 * outbound engine. Each entry point resolves its own inputs (campaign / queue / script /
 * caller ID / dial prefix / call_uuid) and then delegates here. This service runs the
 * SAME steps, in the SAME order, differing only by call_mode (MANUAL vs PREVIEW):
 *
 *   1. agent status guard (manual-status lock)
 *   2. pause the agent's inbound queue membership for the outbound call
 *   3. create Conversation + VoiceCall (findOrCreate, direction=outbound, explicit queue)
 *   4. effective status → in_call (voice_outbound)
 *   5. assign the script to the conversation
 *   6. persist voice_calls.asterisk_queue_name (write-once)
 *   7. build the 7 SIP INVITE headers
 *   8. return the normalized preparation
 *
 * It never touches JsSIP, reports, QueueLog, telephony_calls, or the linkedid
 * correlation — those are downstream and shared already.
 */
class OutboundCallPreparationService
{
    public const CALL_MODE_MANUAL = 'MANUAL';
    public const CALL_MODE_PREVIEW = 'PREVIEW';

    public function __construct(
        private readonly VoiceConversationService $voiceConversationService,
        private readonly AgentStatusService $agentStatusService,
    ) {}

    /**
     * @param array{
     *   campaign: \App\Models\Campaign,
     *   queue: \App\Models\OmniQueue,
     *   script: \App\Models\Script,
     *   clean_phone: string,
     *   caller_id: array{number: string, voice_caller_id_id: ?int, source: string},
     *   dial_prefix: string,
     *   call_mode: string,
     *   call_uuid: string,
     *   session_uuid?: ?string
     * } $context  Fully-resolved inputs from the entry point.
     *
     * @return array{
     *   call_uuid: string,
     *   conversation: \App\Models\Conversation,
     *   voice_call: \App\Models\VoiceCall,
     *   queue_name: string,
     *   call_mode: string,
     *   extra_headers: array<int, string>,
     *   effective: array{status: string, since: \Illuminate\Support\Carbon}
     * }
     */
    public function prepare(User $user, array $context): array
    {
        $callMode = (string) $context['call_mode'];
        $queue = $context['queue'];
        $callerId = $context['caller_id'];
        $callUuid = (string) $context['call_uuid'];

        // ── 1. Agent status guard ────────────────────────────────────────────
        if ($this->agentStatusService->isManualStatusChangeLocked($user)) {
            throw ValidationException::withMessages([
                'voice' => 'Finish the current voice interaction before starting another outbound call.',
            ]);
        }

        // ── 2. Pause the agent's inbound queue membership for this outbound call ─
        $this->agentStatusService->pauseQueueForOutboundVoice($user);

        // ── 3. Create Conversation + VoiceCall (idempotent by tenant + call_uuid) ─
        try {
            $result = $this->voiceConversationService->findOrCreate(
                $user,
                $callUuid,
                'outbound',
                (string) $context['clean_phone'],
                $context['session_uuid'] ?? null,
                null,
                (int) $context['campaign']->id,
                [],
                [
                    'caller_id_number' => $callerId['number'],
                    'voice_caller_id_id' => $callerId['voice_caller_id_id'],
                    'caller_id_source' => $callerId['source'],
                ],
                (int) $queue->id,
            );
        } catch (\Throwable $e) {
            $this->agentStatusService->restoreQueueAvailabilityForRequestedStatus($user, null, 'outbound_prepare_failed');
            throw $e;
        }

        $conversation = $result['conversation'];
        $voiceCall = $result['voice_call'];

        // ── 4. Effective status → in_call ────────────────────────────────────
        $this->agentStatusService->transitionVoiceEffectiveStatus($user, 'in_call', $voiceCall->id, 'voice_outbound');

        // ── 5. Assign the script to the conversation ─────────────────────────
        $scriptId = (int) $context['script']->id;
        if ((int) ($conversation->script_id ?? 0) !== $scriptId) {
            $conversation->update(['script_id' => $scriptId]);
        }

        // ── 6. Persist the technical queue name (write-once) ─────────────────
        // Authoritative from the validated queue's Voice settings — never from the
        // frontend. Inbound fills this via its own SIP-identity path; outbound would
        // otherwise leave it NULL. Written only when empty so an idempotent re-prepare
        // never clobbers an existing value.
        $queueName = trim((string) $queue->voiceSettings->asterisk_queue_name);
        if ((string) ($voiceCall->asterisk_queue_name ?? '') === '') {
            $voiceCall->forceFill(['asterisk_queue_name' => $queueName])->save();
        }

        // ── 7. Build the 7 SIP INVITE headers ────────────────────────────────
        // All seven headers are MANDATORY for BOTH entry points (Manual Dial and Preview
        // Dialer). buildOutboundHeaders() aborts the call if any value is empty, so neither
        // flow ever dials with an incomplete header set — a caller with an unresolved
        // Caller-ID or Dial-Prefix never reaches the INVITE. The ONLY difference between the
        // two flows is X-XEDIK-Call-Mode (MANUAL vs PREVIEW). tenant_id is backend-
        // authoritative (never from the frontend); queue_id is intentionally NOT a header —
        // the PBX only needs Queue-Name; Laravel recovers omni_queue_id via
        // call_uuid → VoiceCall → Conversation.
        $extraHeaders = $this->buildOutboundHeaders([
            'X-XEDIK-Conversation-Id' => (string) $conversation->id,
            'X-XEDIK-Call-UUID' => $callUuid,
            'X-XEDIK-Tenant-Id' => (string) $user->tenant_id,
            'X-XEDIK-Queue-Name' => $queueName,
            'X-XEDIK-Call-Mode' => $callMode,
            'X-XEDIK-Caller-ID' => (string) ($callerId['number'] ?? ''),
            'X-XEDIK-Dial-Prefix' => (string) ($context['dial_prefix'] ?? ''),
        ]);

        // ── 8. Normalized preparation result ─────────────────────────────────
        return [
            'call_uuid' => $callUuid,
            'conversation' => $conversation,
            'voice_call' => $voiceCall,
            'queue_name' => $queueName,
            'call_mode' => $callMode,
            'extra_headers' => $extraHeaders,
            'effective' => $this->agentStatusService->resolveEffectiveStatus($user),
        ];
    }

    /**
     * Build the SIP INVITE headers from backend-resolved values only. Each entry becomes
     * exactly one "Name: value" line, sanitized against CR/LF header injection; a value
     * that reduces to empty aborts the call (never emit an incomplete/ambiguous set).
     *
     * @param array<string, string> $pairs  header name => raw value
     * @return array<int, string>
     */
    private function buildOutboundHeaders(array $pairs): array
    {
        $headers = [];

        foreach ($pairs as $name => $value) {
            $clean = $this->sanitizeHeaderValue($value);

            if ($clean === '') {
                throw ValidationException::withMessages([
                    'voice' => 'Unable to build a complete outbound call header set.',
                ]);
            }

            $headers[] = $name . ': ' . $clean;
        }

        return $headers;
    }

    /**
     * Strip CR/LF and other control characters so a value can never inject an extra SIP
     * header line, and collapse surrounding whitespace.
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value));
    }
}
