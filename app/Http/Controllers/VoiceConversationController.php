<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Script;
use App\Models\VoiceCall;
use App\Services\AgentStatusService;
use App\Services\Scripts\ConversationScriptResolver;
use App\Services\Telephony\TelephonyCallWrapupService;
use App\Services\Voice\QuickCallPreparationService;
use App\Services\Voice\VoiceConversationService;
use App\Services\Voice\VoiceLinkedidCorrelationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class VoiceConversationController extends Controller
{
    public function __construct(
        private readonly VoiceConversationService $voiceService,
        private readonly ConversationScriptResolver $scriptResolver,
        private readonly AgentStatusService $agentStatusService,
        private readonly QuickCallPreparationService $quickCallPreparationService,
        private readonly TelephonyCallWrapupService $telephonyWrapup,
        private readonly VoiceLinkedidCorrelationService $linkedidCorrelation,
    ) {}

    public function prepareQuickCall(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'campaign_id' => 'required|integer',
            'queue_id' => 'required|integer',
            'script_id' => 'required|integer',
            'phone_number' => 'required|string|max:80',
            'session_uuid' => 'nullable|uuid',
        ]);

        $prepared = $this->quickCallPreparationService->prepare(
            $user,
            (int) $validated['campaign_id'],
            (int) $validated['queue_id'],
            (int) $validated['script_id'],
            (string) $validated['phone_number'],
            $validated['session_uuid'] ?? null,
            $this->outboundAttemptId($request),
            $this->optionalHeader($request, 'X-XEDIK-Browser-Tab-Id'),
        );

        return response()->json([
            'ok' => true,
            'quick_call' => $prepared,
        ]);
    }

    /**
     * POST /console/voice/conversations
     *
     * Create or recover a Conversation + VoiceCall for a Voice interaction.
     * Idempotent by (tenant_id, call_uuid).
     *
     * The frontend sends:
     *   call_uuid     — UUID part of voice-local-{uuid}
     *   direction     — inbound | outbound
     *   phone         — raw phone as received from SIP
     *   session_uuid? — VoiceSession UUID for FK correlation (optional)
     *   origin_conversation_id? — when call starts from another conversation context
     *   campaign_id?  — explicit campaign for outbound calls (required when script_id present)
     *   script_id?    — explicit script for outbound calls (validated against campaign)
     *
     * Backend normalizes the phone. Frontend phone value is used as a hint only.
     * Never exposes SIP credentials or session secrets.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'call_uuid'               => 'required|string|max:36|regex:/^[0-9a-f\-]+$/i',
            'direction'               => 'required|in:inbound,outbound',
            'phone'                   => 'required|string|max:80',
            'ani'                     => 'nullable|string|max:80',
            'dnis'                    => 'nullable|string|max:80',
            'queue_name'              => ['nullable', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/'],
            'asterisk_linkedid'       => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+(?:\.[0-9]+)?$/'],
            'sip_call_id'             => 'nullable|string|max:255',
            'browser_voice_session_uuid' => 'nullable|uuid',
            'session_uuid'            => 'nullable|uuid',
            'origin_conversation_id'  => 'nullable|integer',
            'campaign_id'             => 'nullable|integer|exists:campaigns,id',
            'script_id'               => 'nullable|integer|exists:scripts,id',
        ]);

        // Validate explicit campaign + script when provided (outbound dialer flow)
        $explicitScript     = null;
        $explicitCampaignId = !empty($validated['campaign_id']) ? (int) $validated['campaign_id'] : null;

        if ($explicitCampaignId && !empty($validated['script_id'])) {
            $campaign = Campaign::withoutGlobalScopes()
                ->where('id', $explicitCampaignId)
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$campaign) {
                return response()->json(['ok' => false, 'error' => 'Campaign not found.'], 422);
            }

            if (!$user->is_superadmin
                && !$user->campaigns()->where('campaigns.id', $campaign->id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Campaign not accessible.'], 403);
            }

            $explicitScript = Script::withoutGlobalScopes()
                ->where('id', (int) $validated['script_id'])
                ->where('tenant_id', $user->tenant_id)
                ->where('campaign_id', $campaign->id)
                ->where('is_active', true)
                ->whereIn('direction', ['outbound', 'both'])
                ->first();

            if (!$explicitScript) {
                return response()->json(['ok' => false, 'error' => 'Script not valid for this campaign.'], 422);
            }
        }

        try {
            $result = $this->voiceService->findOrCreate(
                $user,
                $validated['call_uuid'],
                $validated['direction'],
                $validated['phone'],
                $validated['session_uuid'] ?? null,
                isset($validated['origin_conversation_id'])
                    ? (int) $validated['origin_conversation_id']
                    : null,
                $validated['direction'] === 'inbound' ? null : $explicitCampaignId,
                [
                    'ani' => $validated['ani'] ?? null,
                    'dnis' => $validated['dnis'] ?? null,
                    'queue_name' => $validated['queue_name'] ?? null,
                    'asterisk_linkedid' => $validated['asterisk_linkedid'] ?? null,
                    'sip_call_id' => $validated['sip_call_id'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
            // Log internally; never expose DB/SIP internals to the frontend
            \Illuminate\Support\Facades\Log::error('VoiceConversationController: store failed', [
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Could not create voice conversation. Please try again.',
            ], 500);
        }

        /** @var Conversation $conversation */
        $conversation = $result['conversation'];
        /** @var VoiceCall $call */
        $call = $result['voice_call'];
        $attemptId = $this->outboundAttemptId($request);

        Log::info('[voice][outbound-diag] call_persisted', [
            'attempt_id' => $attemptId,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'voice_call_id' => $call->id,
            'call_uuid' => $call->call_uuid,
            'direction' => $call->direction,
        ]);

        // Phase 1E-B3: an inbound offer becoming visible (new or reoffered) is
        // effective Ringing for the agent, regardless of the agent's requested
        // status. Outbound is intentionally left untouched — Phase 1E-B2 scoped
        // the offer lifecycle to inbound only, and outbound dialing has its own
        // separate flow (Make Call/Dialer), which this phase does not touch.
        if ($validated['direction'] === 'inbound') {
            try {
                $this->agentStatusService->transitionVoiceEffectiveStatus($user, 'ringing', $call->id, 'voice');
            } catch (\Throwable $e) {
                Log::warning('[AgentEffectiveStatus] ringing transition failed', [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'voice_call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Resolve script: explicit selection from dialer takes priority over resolver.
        // Persist script_id on the conversation so it survives page reloads.
        if ($explicitScript) {
            if ((int) $conversation->script_id !== $explicitScript->id) {
                $conversation->update(['script_id' => $explicitScript->id]);
            }
            $resolvedScript = $explicitScript;
            $scriptSource   = 'selected';
        } else {
            $conversation->load(['omniQueue.script', 'omniQueue.campaign', 'lead.campaign']);
            $scriptData     = $this->scriptResolver->resolve($conversation);
            $resolvedScript = $scriptData['script'];
            $scriptSource   = $scriptData['script_source'];
        }

        $scriptPayload = $resolvedScript ? [
            'id'         => $resolvedScript->id,
            'name'       => $resolvedScript->name,
            'type'       => $resolvedScript->type,
            'direction'  => $resolvedScript->direction,
            'body'       => $resolvedScript->type === 'builder' ? $resolvedScript->body : null,
            'url'        => $resolvedScript->type === 'url'     ? $resolvedScript->url  : null,
            'url_target' => $resolvedScript->url_target,
            'url_params' => $resolvedScript->url_params ?? [],
        ] : null;

        return response()->json([
            'ok'              => true,
            'created'         => (bool) ($result['created'] ?? false),
            'reused'          => (bool) ($result['reused'] ?? false),
            'conversation_id' => $conversation->id,
            'voice_call_id'   => $call->id,
            'call_uuid'       => $call->call_uuid,
            'asterisk_linkedid' => $call->asterisk_linkedid,
            'queue_id'        => $conversation->omni_queue_id,
            'campaign_id'     => $conversation->campaign_id ?? $conversation->omniQueue?->campaign_id,
            'lead_id'         => $conversation->lead_id,
            'remote_phone'    => $conversation->from_email,
            'ani_normalized'   => $call->ani_normalized,
            'dnis_normalized'  => $call->dnis_normalized,
            'ani_display'      => $call->ani_display,
            'dnis_display'     => $call->dnis_display,
            'script'          => $scriptPayload,
            'script_source'   => $scriptSource,
        ], 201);
    }

    /**
     * POST /console/voice/calls/{callUuid}/linkedid
     *
     * Real-time outbound correlation (Phase 1 of the SIP MESSAGE flow). The browser
     * relays the linkedid that Asterisk delivered for an outbound call; the backend
     * stores it on voice_calls.asterisk_linkedid so the existing machinery
     * (TelephonyCallWrapupService / QueueLogImportService) can create and enrich
     * telephony_calls without waiting for QueueLog.
     *
     * Security: tenant_id/pbx_id/user_id/direction are NEVER taken from the browser.
     * The VoiceCall is resolved by (tenant_id, call_uuid); it must be OUTBOUND, its
     * conversation_id must match the payload, and the Conversation must be assigned to
     * the authenticated agent. This endpoint never writes telephony_calls.
     */
    public function correlateLinkedid(Request $request, string $callUuid): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'linkedid' => ['required', 'string', 'max:64', 'regex:/^[0-9]+\.[0-9]+$/'],
            'conversation_id' => ['required', 'integer', 'min:1'],
        ]);

        // Resolve by (tenant_id, call_uuid) — never trust the UUID alone.
        $call = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('call_uuid', $callUuid)
            ->first();

        if (!$call) {
            return response()->json(['ok' => false, 'error' => 'Voice call not found.'], 404);
        }

        if ($call->direction !== 'outbound') {
            return response()->json(['ok' => false, 'error' => 'Linkedid correlation is only allowed for outbound calls.'], 422);
        }

        if ((int) $call->conversation_id !== (int) $validated['conversation_id']) {
            return response()->json(['ok' => false, 'error' => 'Conversation does not match this call.'], 422);
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $call->conversation_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$conversation || (int) $conversation->assigned_to !== (int) $user->id) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 403);
        }

        $result = $this->linkedidCorrelation->correlate(
            $call,
            (string) $validated['linkedid'],
            'sip_message_relay'
        );

        $status = $result === VoiceLinkedidCorrelationService::RESULT_CONFLICT ? 409 : 200;

        return response()->json([
            'ok' => $result !== VoiceLinkedidCorrelationService::RESULT_CONFLICT,
            'status' => $result,
            'call_uuid' => $call->call_uuid,
            'conversation_id' => (int) $call->conversation_id,
        ], $status);
    }

    /**
     * PATCH /console/voice/calls/{callUuid}/state
     *
     * Update the technical state of an existing VoiceCall.
     * Authorized: agent must be assigned to the parent Conversation.
     * Transitions are validated — terminal states cannot regress.
     * Backend calculates all timestamps authoritatively.
     */
    public function updateState(Request $request, string $callUuid): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->tenant_id) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'call_state' => 'required|in:' . implode(',', VoiceCall::ALL_STATES),
        ]);
        $attemptId = $this->outboundAttemptId($request);

        // Locate by (tenant_id, call_uuid) — never trust the UUID alone
        $call = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('call_uuid', $callUuid)
            ->first();

        if (!$call) {
            return response()->json(['ok' => false, 'error' => 'Voice call not found.'], 404);
        }

        // Authorization: agent must be assigned to the conversation
        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $call->conversation_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$conversation || $conversation->assigned_to !== $user->id) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 403);
        }

        $fromState = $call->call_state;
        try {
            $updated = $this->voiceService->updateCallState($call, $validated['call_state']);
        } catch (\InvalidArgumentException $e) {
            Log::info('[voice][outbound-diag] call_state_patch', [
                'attempt_id' => $attemptId,
                'voice_call_id' => $call->id,
                'call_uuid' => $call->call_uuid,
                'from_state' => $fromState,
                'to_state' => $validated['call_state'],
                'accepted' => false,
                'http_status' => 422,
                'rejected_transition' => true,
            ]);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        Log::info('[voice][outbound-diag] call_state_patch', [
            'attempt_id' => $attemptId,
            'voice_call_id' => $updated->id,
            'call_uuid' => $updated->call_uuid,
            'from_state' => $fromState,
            'to_state' => $validated['call_state'],
            'accepted' => true,
            'http_status' => 200,
        ]);

        // Layer the agent's Voice EFFECTIVE occupancy state on top of their
        // requested status. Inbound unanswered terminals keep the Reject/Reoffer
        // no-Wrap-Up rule; outbound answered terminals reuse the same Wrap-Up
        // lifecycle, while never-answered outbound attempts restore requested
        // queue availability.
        if (in_array($updated->direction, ['inbound', 'outbound'], true)) {
            try {
                if ($updated->call_state === 'active') {
                    $this->agentStatusService->transitionVoiceEffectiveStatus(
                        $user,
                        'in_call',
                        $updated->id,
                        $updated->direction === 'outbound' ? 'voice_outbound' : 'voice'
                    );
                } elseif (in_array($updated->call_state, VoiceCall::TERMINAL_STATES, true)) {
                    if ($updated->answered_at !== null) {
                        if (
                            $conversation->status === 'active'
                            && $conversation->disposition === null
                            && $conversation->resolved_at === null
                        ) {
                            $this->agentStatusService->enterVoiceWrapUp($user, $updated->id);
                            $this->telephonyWrapup->startFromVoiceCall($updated);
                        }
                    } else {
                        // Never-answered terminal (Reject/Missed/Cancelled/etc.) —
                        // reuses the completed Phase 1E-B2 rule: no Wrap-Up, resume
                        // whatever the agent already requested (normally Ready).
                        $this->agentStatusService->resumeRequestedStatusAfterVoiceWork($user, 'voice');
                        if ($updated->direction === 'outbound') {
                            $this->agentStatusService->restoreQueueAvailabilityForRequestedStatus($user, null, 'outbound_unanswered');
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[AgentEffectiveStatus] call-state transition failed', [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'voice_call_id' => $updated->id,
                    'call_state' => $updated->call_state,
                    'direction' => $updated->direction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ok'             => true,
            'call_state'     => $updated->call_state,
            'answered_at'    => $updated->answered_at?->toISOString(),
            'ended_at'       => $updated->ended_at?->toISOString(),
            'duration_seconds' => $updated->duration_seconds,
            'talk_seconds'   => $updated->talk_seconds,
        ]);
    }

    private function outboundAttemptId(Request $request): ?string
    {
        return $this->optionalHeader($request, 'X-XEDIK-Attempt-Id');
    }

    private function optionalHeader(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name, ''));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 128);
    }
}
