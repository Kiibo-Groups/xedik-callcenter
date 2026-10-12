<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\AgentSession;
use App\Models\User;
use App\Models\VoiceExtension;
use App\Models\VoiceSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AsteriskQueueMemberAvailabilityService
{
    public function __construct(
        private readonly AsteriskQueueMemberRealtimeService $members
    ) {}

    public function unpauseExistingMembershipsForReady(User $user, ?string $browserTabId = null, string $reason = 'ready'): array
    {
        $resolved = $this->resolveMemberships($user);

        if ($resolved['queue_names'] === []) {
            $this->log('ready skipped without static memberships', $user, $resolved, [
                'reason' => $reason,
            ]);

            return $this->result(['skipped' => $resolved['skipped']]);
        }

        if (!$this->hasUsableVoiceSession($user, $resolved['extension'], $browserTabId)) {
            $this->log('ready blocked without usable voice session', $user, $resolved, [
                'reason' => $reason,
            ], 'warning');

            throw new QueueMemberAvailabilityException('Connect Voice before becoming ready for calls.');
        }

        return $this->setPausedForResolvedMemberships($user, $resolved, false, $reason);
    }

    public function pauseExistingMembershipsForUser(User $user, string $reason = 'unavailable'): array
    {
        $resolved = $this->resolveMemberships($user, includeInactiveVoiceExtension: true);

        if ($resolved['queue_names'] === []) {
            return $this->result(['skipped' => $resolved['skipped']]);
        }

        try {
            return $this->setPausedForResolvedMemberships($user, $resolved, true, $reason);
        } catch (Throwable $e) {
            $this->logFailure('pause failed', $user, $resolved, $e, ['reason' => $reason]);

            return $this->result(['failed' => 1, 'skipped' => $resolved['skipped']]);
        }
    }

    public function pauseForConsoleBootstrap(User $user, string $reason = 'bootstrap_pause'): array
    {
        $resolved = $this->resolveMemberships($user, includeInactiveVoiceExtension: true);

        if ($resolved['queue_names'] === []) {
            return $this->result(['skipped' => $resolved['skipped']]);
        }

        if ($this->hasAuthoritativeReadyVoiceSession($user, $resolved)) {
            $this->log('bootstrap skipped because an authoritative ready voice session exists', $user, $resolved, [
                'action' => $reason,
                'skip_reason' => 'authoritative_ready_session_exists',
            ]);

            return $this->result(['skipped' => 1, 'ignored' => 1]);
        }

        $this->log('bootstrap fail-closed pause requested', $user, $resolved, [
            'action' => $reason,
        ]);

        try {
            return $this->setPausedForResolvedMemberships($user, $resolved, true, $reason);
        } catch (Throwable $e) {
            $this->logFailure('bootstrap pause failed', $user, $resolved, $e, ['action' => $reason]);

            return $this->result(['failed' => 1, 'skipped' => $resolved['skipped']]);
        }
    }

    public function pauseForAuthoritativePagehide(User $user, string $sessionUuid, string $browserTabId): array
    {
        $session = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('session_uuid', $sessionUuid)
            ->first();

        if (!$session) {
            return $this->logPagehideSkip($user, 'session_not_found');
        }

        if (!hash_equals((string) $session->browser_tab_id, $browserTabId)) {
            return $this->logPagehideSkip($user, 'browser_tab_mismatch', $session);
        }

        if (!in_array($session->status, ['pending', 'registered', 'active'], true)
            || $session->replaced_at
            || $session->revoked_at) {
            return $this->logPagehideSkip($user, 'session_not_authoritative', $session);
        }

        $currentSessionId = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'registered', 'active'])
            ->whereNull('replaced_at')
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        if ((int) $currentSessionId !== (int) $session->id) {
            return $this->logPagehideSkip($user, 'newer_session_current', $session);
        }

        return $this->pauseExistingMembershipsForVoiceSession($session, 'pagehide_pause');
    }

    public function pauseExistingMembershipsForVoiceSession(VoiceSession $session, string $reason = 'voice_session_unavailable'): array
    {
        $user = $session->user;
        $extension = $session->voiceExtension;

        if (!$user || !$extension) {
            return $this->result(['skipped' => 1]);
        }

        $interface = $this->interfaceFor($extension);
        if (!$interface) {
            return $this->result(['skipped' => 1]);
        }

        $queueNames = $this->queueNamesForUser($user);
        if ($queueNames === []) {
            return $this->result(['skipped' => 1]);
        }

        $resolved = [
            'extension' => $extension,
            'interface' => $interface,
            'queue_names' => $queueNames,
            'skipped' => 0,
        ];

        try {
            return $this->setPausedForResolvedMemberships($user, $resolved, true, $reason);
        } catch (Throwable $e) {
            $this->logFailure('session pause failed', $user, $resolved, $e, ['reason' => $reason]);

            return $this->result(['failed' => 1]);
        }
    }

    public function restoreReadyAvailabilityForSession(VoiceSession $session, ?string $browserTabId = null, string $reason = 'voice_session_ready'): array
    {
        $user = $session->user;

        if (!$user) {
            return $this->result(['skipped' => 1]);
        }

        $agentSession = AgentSession::where('user_id', $user->id)->first();
        if ($agentSession?->status !== 'ready') {
            return $this->result(['skipped' => 1]);
        }

        return $this->unpauseExistingMembershipsForReady($user, $browserTabId, $reason);
    }

    private function setPausedForResolvedMemberships(User $user, array $resolved, bool $paused, string $reason): array
    {
        $existingRows = $this->members->countRowsForInterfaces(
            $resolved['queue_names'],
            $resolved['interface']
        );

        if (!$paused && $existingRows === 0) {
            $this->log('ready blocked because static membership rows are missing', $user, $resolved, [
                'reason' => $reason,
            ], 'warning');

            throw new QueueMemberAvailabilityException('Voice queue membership is not configured yet. Retry queue configuration before becoming ready for calls.');
        }

        $updated = $this->members->updatePausedForInterfaces(
            $resolved['queue_names'],
            $resolved['interface'],
            $paused
        );

        $this->log($paused ? 'memberships paused' : 'memberships unpaused', $user, $resolved, [
            'reason' => $reason,
            'memberships_existing' => $existingRows,
            'updated' => $updated,
        ]);

        return $this->result([
            'updated' => $updated,
            'skipped' => $resolved['skipped'],
            'paused' => $paused ? 1 : 0,
        ]);
    }

    private function resolveMemberships(User $user, bool $includeInactiveVoiceExtension = false): array
    {
        $extension = $this->voiceExtensionFor($user, $includeInactiveVoiceExtension);
        $interface = $extension ? $this->interfaceFor($extension) : null;

        if (!$user->is_active || !$extension || !$interface) {
            return [
                'extension' => $extension,
                'interface' => $interface,
                'queue_names' => [],
                'skipped' => 1,
            ];
        }

        $queueNames = $this->queueNamesForUser($user);

        return [
            'extension' => $extension,
            'interface' => $interface,
            'queue_names' => $queueNames,
            'skipped' => $queueNames === [] ? 1 : 0,
        ];
    }

    private function queueNamesForUser(User $user): array
    {
        try {
            return $user->omniQueues()
                ->with('voiceSettings')
                ->wherePivot('active', true)
                ->get()
                ->filter(function ($queue) use ($user): bool {
                    $settings = $queue->voiceSettings;

                    return (int) $queue->tenant_id === (int) $user->tenant_id
                        && $queue->supportsVoice()
                        && $settings
                        && (bool) $settings->is_enabled
                        && filled($settings->asterisk_queue_name);
                })
                ->map(fn ($queue) => (string) $queue->voiceSettings->asterisk_queue_name)
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::debug('[QueueMemberAvailability] queue membership lookup skipped', [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 200),
            ]);

            return [];
        }
    }

    private function voiceExtensionFor(User $user, bool $includeInactive): ?VoiceExtension
    {
        $query = VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id);

        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        return $query->orderByDesc('is_active')->orderByDesc('id')->first();
    }

    private function interfaceFor(VoiceExtension $extension): ?string
    {
        $endpoint = trim((string) $extension->pjsip_endpoint);

        if ($endpoint === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $endpoint)) {
            return null;
        }

        $interface = 'PJSIP/' . $endpoint;

        return strlen($interface) <= 80 ? $interface : null;
    }

    private function hasUsableVoiceSession(User $user, VoiceExtension $extension, ?string $browserTabId): bool
    {
        $staleSeconds = max(1, (int) config('voice.heartbeat_stale_seconds', 900));

        $query = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('voice_extension_id', $extension->id)
            ->whereIn('status', ['registered', 'active'])
            ->whereNull('revoked_at')
            ->whereNull('replaced_at')
            ->where('expires_at', '>', now())
            ->where('last_seen_at', '>=', now()->subSeconds($staleSeconds));

        if (is_string($browserTabId) && $browserTabId !== '') {
            $query->where('browser_tab_id', $browserTabId);
        }

        return $query->exists();
    }

    private function hasAuthoritativeReadyVoiceSession(User $user, array $resolved): bool
    {
        $extension = $resolved['extension'] ?? null;

        if (!$extension instanceof VoiceExtension || !(bool) $extension->is_active) {
            return false;
        }

        $agentSession = AgentSession::where('user_id', $user->id)->first();
        if ($agentSession?->status !== 'ready') {
            return false;
        }

        return $this->hasUsableVoiceSession($user, $extension, null);
    }

    private function log(string $message, User $user, array $resolved, array $context = [], string $level = 'info'): void
    {
        Log::log($level, '[QueueMemberAvailability] ' . $message, array_merge([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'interface' => $resolved['interface'] ?? null,
            'memberships_resolved' => count($resolved['queue_names'] ?? []),
            'skipped' => $resolved['skipped'] ?? 0,
        ], $context));
    }

    private function logFailure(string $message, User $user, array $resolved, Throwable $e, array $context = []): void
    {
        $this->log($message, $user, $resolved, array_merge($context, [
            'error_class' => $e::class,
            'message' => Str::limit($e->getMessage(), 300),
        ]), 'warning');
    }

    private function logPagehideSkip(User $user, string $reason, ?VoiceSession $session = null): array
    {
        Log::info('[QueueMemberAvailability] stale beacon ignored', [
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'voice_session_id' => $session?->id,
            'action' => 'stale_beacon_ignored',
            'skip_reason' => $reason,
        ]);

        return $this->result(['skipped' => 1, 'ignored' => 1]);
    }

    private function result(array $overrides = []): array
    {
        return array_replace([
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'paused' => null,
            'ignored' => 0,
        ], $overrides);
    }
}
