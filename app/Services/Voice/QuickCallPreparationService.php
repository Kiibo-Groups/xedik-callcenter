<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\Campaign;
use App\Models\OmniQueue;
use App\Models\Script;
use App\Models\User;
use App\Models\VoiceCallerId;
use App\Models\VoiceExtension;
use App\Models\VoiceTenantSetting;
use App\Services\AgentStatusService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuickCallPreparationService
{
    private const CALL_MODE_MANUAL = OutboundCallPreparationService::CALL_MODE_MANUAL;

    public function __construct(
        private readonly GatewayResolutionService $gatewayResolutionService,
        private readonly VoiceConversationService $voiceConversationService,
        private readonly AgentStatusService $agentStatusService,
        private readonly OutboundCallPreparationService $outboundPreparation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function prepare(
        User $user,
        int $campaignId,
        int $queueId,
        int $scriptId,
        string $phoneNumber,
        ?string $sessionUuid = null,
        ?string $attemptId = null,
        ?string $browserTabId = null,
    ): array {
        $startedAt = microtime(true);
        $callUuid = null;
        $voiceCall = null;
        $cleanPhone = null;
        $manualStatusLock = null;
        $effectiveStatusBefore = $this->safeEffectiveStatus($user);
        $outcome = 'failed';

        try {
            // ── Input resolution (Manual entry point) ────────────────────────
            $campaign = $this->campaignForUser($user, $campaignId);
            $queue = $this->queueForCampaign($user, $campaign, $queueId);
            $script = $this->scriptForCampaign($user, $campaign, $scriptId);
            $extension = $this->outboundExtension($user);
            $cleanPhone = $this->cleanPhoneNumber($phoneNumber);
            $callerId = $this->resolveCallerId($user, $campaign, $extension);
            $dialPrefix = $this->gatewayResolutionService->resolveDialPrefixForCampaign($campaign);

            if (!$dialPrefix) {
                throw ValidationException::withMessages([
                    'campaign_id' => 'No outbound voice gateway is configured for this campaign or tenant.',
                ]);
            }

            $manualStatusLock = $this->agentStatusService->isManualStatusChangeLocked($user);

            // ── Shared outbound preparation (identical pipeline as Preview) ──
            $core = $this->outboundPreparation->prepare($user, [
                'campaign' => $campaign,
                'queue' => $queue,
                'script' => $script,
                'clean_phone' => $cleanPhone,
                'caller_id' => $callerId,
                'dial_prefix' => $dialPrefix,
                'call_mode' => self::CALL_MODE_MANUAL,
                'call_uuid' => (string) Str::uuid(),
                'session_uuid' => $sessionUuid,
            ]);

            $callUuid = $core['call_uuid'];
            $conversation = $core['conversation'];
            $voiceCall = $core['voice_call'];
            $queueName = $core['queue_name'];
            $outcome = 'success';

            return [
                'call_uuid' => $callUuid,
                'conversation_id' => $conversation->id,
                'voice_call_id' => $voiceCall->id,
                'phone_number' => $cleanPhone,
                'queue_id' => (int) $queue->id,
                'queue_name' => $queueName,
                'call_mode' => self::CALL_MODE_MANUAL,
                'dial_prefix' => $dialPrefix,
                'caller_id' => [
                    'number' => $callerId['number'],
                    'voice_caller_id_id' => $callerId['voice_caller_id_id'],
                    'source' => $callerId['source'],
                ],
                'campaign' => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                ],
                'script' => [
                    'id' => $script->id,
                    'name' => $script->name,
                ],
                'extra_headers' => $core['extra_headers'],
                'agent_status' => $this->agentStatusPayload($user, $core['effective']),
            ];
        } finally {
            Log::info('[voice][outbound-diag] quick_call_prepare', [
                'attempt_id' => $attemptId,
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'browser_tab_id' => $browserTabId,
                'voice_session_uuid' => $sessionUuid,
                'call_uuid' => $callUuid,
                'destination_masked' => $this->maskDestination($cleanPhone ?? $phoneNumber),
                'effective_status_before' => $effectiveStatusBefore,
                'manual_status_lock' => $manualStatusLock,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'outcome' => $outcome,
            ]);
        }
    }

    private function safeEffectiveStatus(User $user): ?string
    {
        try {
            return $this->agentStatusService->resolveEffectiveStatus($user)['status'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function maskDestination(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /**
     * @param array{status: string, since: \Illuminate\Support\Carbon} $effective
     * @return array{status: string, pause_reason_id: ?int, pause_reason_name: ?string, pause_reason_color: ?string, status_since: ?string, effective_status: string, effective_status_label: string, effective_since: ?string}
     */
    private function agentStatusPayload(User $user, array $effective): array
    {
        $session = \App\Models\AgentSession::withoutGlobalScopes()
            ->with('pauseReason')
            ->where('user_id', $user->id)
            ->first();

        return [
            'status' => $session?->status ?? 'offline',
            'pause_reason_id' => $session?->pause_reason_id ? (int) $session->pause_reason_id : null,
            'pause_reason_name' => $session?->pauseReason?->name,
            'pause_reason_color' => $session?->pauseReason?->color,
            'status_since' => optional($session?->status_since)->toIso8601String(),
            'effective_status' => $effective['status'],
            'effective_status_label' => 'Outbound Call',
            'effective_since' => $effective['since']?->toIso8601String(),
        ];
    }

    private function campaignForUser(User $user, int $campaignId): Campaign
    {
        $campaign = Campaign::withoutGlobalScopes()
            ->where('id', $campaignId)
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('is_active', true)
            ->first();

        if (!$campaign) {
            throw ValidationException::withMessages([
                'campaign_id' => 'Campaign not found.',
            ]);
        }

        if (!$user->is_superadmin
            && !$user->campaigns()->where('campaigns.id', $campaign->id)->exists()) {
            throw ValidationException::withMessages([
                'campaign_id' => 'Campaign not accessible.',
            ]);
        }

        return $campaign;
    }

    /**
     * Manual Dial: the queue the agent classifies the outbound call under. It must
     * belong to the tenant and to the selected campaign, be active, be assigned to
     * the agent (active membership), and expose an enabled Voice setting with a
     * non-empty asterisk_queue_name. queue_name is resolved here — never trusted
     * from the frontend.
     */
    private function queueForCampaign(User $user, Campaign $campaign, int $queueId): OmniQueue
    {
        $queue = OmniQueue::withoutGlobalScopes()
            ->where('id', $queueId)
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('campaign_id', (int) $campaign->id)
            ->where('active', true)
            ->with('voiceSettings')
            ->first();

        if (!$queue) {
            throw ValidationException::withMessages([
                'queue_id' => 'Queue not valid for this campaign.',
            ]);
        }

        if (!$user->is_superadmin
            && !$user->omniQueues()
                ->where('omni_queues.id', $queue->id)
                ->wherePivot('active', true)
                ->exists()) {
            throw ValidationException::withMessages([
                'queue_id' => 'Queue not accessible.',
            ]);
        }

        $settings = $queue->voiceSettings;
        if (!$settings
            || !$settings->is_enabled
            || trim((string) $settings->asterisk_queue_name) === '') {
            throw ValidationException::withMessages([
                'queue_id' => 'Queue is not enabled for voice.',
            ]);
        }

        return $queue;
    }

    private function scriptForCampaign(User $user, Campaign $campaign, int $scriptId): Script
    {
        $script = Script::withoutGlobalScopes()
            ->where('id', $scriptId)
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('campaign_id', (int) $campaign->id)
            ->where('is_active', true)
            ->whereIn('direction', ['outbound', 'both'])
            ->first();

        if (!$script) {
            throw ValidationException::withMessages([
                'script_id' => 'Script not valid for this campaign.',
            ]);
        }

        return $script;
    }

    private function cleanPhoneNumber(string $phoneNumber): string
    {
        $clean = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (strlen($clean) < 7 || strlen($clean) > 15) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid phone number.',
            ]);
        }

        return $clean;
    }

    private function outboundExtension(User $user): VoiceExtension
    {
        $extension = VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('user_id', (int) $user->id)
            ->where('is_active', true)
            ->where('allow_outbound', true)
            ->first();

        if (!$extension) {
            throw ValidationException::withMessages([
                'voice' => 'User is not enabled for outbound voice calls.',
            ]);
        }

        return $extension;
    }

    /**
     * @return array{number: string, voice_caller_id_id: ?int, source: string}
     */
    private function resolveCallerId(User $user, Campaign $campaign, VoiceExtension $extension): array
    {
        $campaignCallerId = trim((string) $campaign->outbound_caller_id);
        if ($campaignCallerId !== '') {
            return [
                'number' => $campaignCallerId,
                'voice_caller_id_id' => $this->matchingTenantCallerIdId($campaignCallerId, (int) $user->tenant_id),
                'source' => 'campaign',
            ];
        }

        $personal = null;
        if ($extension->caller_id_source === 'user' && $extension->personal_caller_id_id) {
            $personal = VoiceCallerId::withoutGlobalScopes()
                ->where('id', (int) $extension->personal_caller_id_id)
                ->first();
        }

        if ($personal
            && (int) $personal->tenant_id === (int) $user->tenant_id
            && $personal->is_active
            && $personal->is_verified) {
            return [
                'number' => $personal->number,
                'voice_caller_id_id' => (int) $personal->id,
                'source' => 'user',
            ];
        }

        $tenantDefault = trim((string) VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', (int) $user->tenant_id)
            ->value('default_caller_id'));

        if ($tenantDefault !== '') {
            return [
                'number' => $tenantDefault,
                'voice_caller_id_id' => $this->matchingTenantCallerIdId($tenantDefault, (int) $user->tenant_id),
                'source' => 'tenant',
            ];
        }

        throw ValidationException::withMessages([
            'caller_id' => 'No caller ID is configured for this campaign, user, or tenant.',
        ]);
    }

    private function matchingTenantCallerIdId(string $number, int $tenantId): ?int
    {
        $id = VoiceCallerId::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('number', $number)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
