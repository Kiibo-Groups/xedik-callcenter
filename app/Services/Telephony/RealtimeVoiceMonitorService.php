<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AgentSession;
use App\Models\AgentStatusLog;
use App\Models\OmniQueue;
use App\Models\RealtimePbxSnapshot;
use App\Models\RealtimeVoiceAgentState;
use App\Models\RealtimeVoiceWaitingCall;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RealtimeVoiceMonitorService
{
    private const SNAPSHOT_FUTURE_TOLERANCE_SECONDS = 5;

    private const VOICE_STATE_PRIORITY = [
        'on_call' => 90,
        'ringing' => 80,
        'outbound_call' => 78,
        'wrap_up' => 75,
        'offline' => 72,
        'paused' => 70,
        'busy' => 60,
        'ready' => 50,
        'not_ready' => 40,
        'unavailable' => 35,
        'unknown' => 20,
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshotForUser(User $user, array $filters = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $tenantId = $this->tenantIdFor($user);
        $staleSeconds = (int) config('realtime_monitor.stale_seconds', 15);
        $offlineSeconds = (int) config('realtime_monitor.offline_seconds', 30);
        $resolvedFilters = $this->resolveFilters($tenantId, $filters);

        $agentRows = $this->agentQuery($tenantId, $resolvedFilters)
            ->with(['user:id,name', 'omniQueue:id,name'])
            ->orderBy('user_id')
            ->orderBy('member_name')
            ->orderBy('external_queue_name')
            ->get();

        $agentSessions = $this->agentSessionsFor($agentRows, $tenantId);
        $voiceSessions = $this->voiceSessionsFor($agentRows, $tenantId);
        $activeCalls = $this->activeVoiceCallsFor($agentRows, $tenantId);
        $wrapUpCalls = $this->wrapUpVoiceCallsFor($agentRows, $tenantId);
        $wrapUpLogs = $this->needsWrapUpStatusLogs($agentRows, $agentSessions, $wrapUpCalls, $now)
            ? $this->wrapUpStatusLogsFor($agentRows, $tenantId)
            : collect();
        $agents = $this->consolidateAgents($agentRows, $agentSessions, $voiceSessions, $activeCalls, $wrapUpCalls, $wrapUpLogs, $now, $staleSeconds, $offlineSeconds);
        $waitingCalls = $this->waitingCallRows($tenantId, $now, $resolvedFilters);
        $freshness = $this->snapshotFreshness($now, $staleSeconds, $offlineSeconds);

        return [
            'generated_at' => $now->toIso8601String(),
            'generated_at_epoch' => $now->getTimestamp(),
            'freshness' => $freshness,
            'kpis' => $this->kpis($agents, $waitingCalls),
            'agents' => array_values($agents),
            'waiting_calls' => $waitingCalls,
            'filters' => [
                'campaign_id' => $resolvedFilters['campaign_id'],
                'queue_id' => $resolvedFilters['queue_id'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptionsForUser(User $user): array
    {
        $tenantId = $this->tenantIdFor($user);

        $queues = OmniQueue::withoutGlobalScopes()
            ->with('campaign:id,name')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereHas('voiceSettings', fn ($query) => $query->where('is_enabled', true))
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'campaign_id']);

        $campaigns = $queues
            ->filter(fn (OmniQueue $queue): bool => $queue->campaign !== null)
            ->map(fn (OmniQueue $queue): array => [
                'id' => (int) $queue->campaign->id,
                'name' => $queue->campaign->name,
            ])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'campaigns' => $campaigns,
            'queues' => $queues
                ->map(fn (OmniQueue $queue): array => [
                    'id' => (int) $queue->id,
                    'name' => $queue->name,
                    'campaign_id' => $queue->campaign_id === null ? null : (int) $queue->campaign_id,
                ])
                ->values()
                ->all(),
        ];
    }

    private function tenantIdFor(User $user): ?int
    {
        if ($user->isSuperAdmin()) {
            return $user->tenant_id === null ? null : (int) $user->tenant_id;
        }

        return (int) $user->tenant_id;
    }

    /**
     * @return Builder<RealtimeVoiceAgentState>
     */
    private function agentQuery(?int $tenantId, array $filters): Builder
    {
        $query = RealtimeVoiceAgentState::withoutGlobalScopes();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($filters['queue_id'] !== null) {
            $query->where('omni_queue_id', $filters['queue_id']);
        } elseif ($filters['campaign_id'] !== null) {
            $query->whereHas('omniQueue', function (Builder $query) use ($filters): void {
                $query->withoutGlobalScopes()->where('campaign_id', $filters['campaign_id']);
            });
        }

        if ($filters['authorized'] === false) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return array<int, array<string, mixed>>
     */
    private function consolidateAgents(Collection $rows, Collection $agentSessions, Collection $voiceSessions, Collection $activeCalls, Collection $wrapUpCalls, Collection $wrapUpLogs, CarbonImmutable $now, int $staleSeconds, int $offlineSeconds): array
    {
        return $rows
            ->groupBy(fn (RealtimeVoiceAgentState $state): string => $this->agentIdentity($state))
            ->map(function (Collection $states) use ($agentSessions, $voiceSessions, $activeCalls, $wrapUpCalls, $wrapUpLogs, $now, $staleSeconds, $offlineSeconds): array {
                /** @var Collection<int, RealtimeVoiceAgentState> $states */
                $primary = $states
                    ->sortByDesc(fn (RealtimeVoiceAgentState $state): int => self::VOICE_STATE_PRIORITY[$this->operationalStatus(
                        $state,
                        $this->agentSessionForState($state, $agentSessions),
                        $this->activeCallForState($state, $activeCalls),
                    )] ?? 0)
                    ->sortByDesc(fn (RealtimeVoiceAgentState $state): int => $state->last_seen_at?->getTimestamp() ?? 0)
                    ->first();

                /** @var RealtimeVoiceAgentState $primary */
                $agentSession = $this->agentSessionForState($primary, $agentSessions);
                $voiceSession = $this->voiceSessionForState($primary, $voiceSessions);
                $activeCall = $this->activeCallForState($primary, $activeCalls);
                $status = $this->operationalStatus($primary, $agentSession, $activeCall);
                $statusSinceDetails = $this->statusSinceDetails($primary, $agentSession, $status, $activeCall, $wrapUpCalls, $wrapUpLogs, $now);
                $statusSince = $statusSinceDetails['timestamp'];
                $pauseReason = $this->pauseReason($primary, $agentSession, $status);
                $loginSince = $status === 'offline'
                    ? null
                    : ($voiceSession?->registered_at ?? $voiceSession?->created_at ?? $primary->connected_since);

                if ($status === 'offline' && $voiceSession !== null) {
                    Log::warning('Realtime voice monitor found active voice session for offline agent session.', [
                        'tenant_id' => $primary->tenant_id,
                        'user_id' => $primary->user_id,
                        'voice_session_id' => $voiceSession->id,
                        'agent_session_status' => $agentSession?->status,
                    ]);
                }

                $queues = $states
                    ->map(fn (RealtimeVoiceAgentState $state): array => [
                        'name' => $state->omniQueue?->name ?: $state->external_queue_name,
                        'external_queue_name' => $state->external_queue_name,
                    ])
                    ->unique(fn (array $queue): string => $queue['name'] . '|' . $queue['external_queue_name'])
                    ->values()
                    ->all();

                return [
                    'identity' => $this->agentIdentity($primary),
                    'agent' => $primary->user?->name ?: ($primary->member_name ?: ($primary->extension ?: 'Unknown')),
                    'extension' => $primary->extension,
                    'status' => $status,
                    'status_label' => $this->stateLabel($status),
                    'operational_status' => $status,
                    'operational_status_label' => $this->stateLabel($status),
                    'activity_direction' => $activeCall?->direction,
                    'status_since' => $this->iso($statusSince),
                    'status_since_epoch' => $this->epoch($statusSince),
                    'status_since_source' => $statusSinceDetails['source'],
                    'raw_status_since' => $statusSinceDetails['raw'],
                    'wrap_up_source' => $status === 'wrap_up' ? $statusSinceDetails['source'] : null,
                    'time_in_status_seconds' => $this->ageSeconds($statusSince, $now),
                    'login_since' => $this->iso($loginSince),
                    'login_since_epoch' => $this->epoch($loginSince),
                    'login_seconds' => $loginSince === null ? null : $this->ageSeconds($loginSince, $now),
                    'pause_reason' => $pauseReason,
                    'state' => $status,
                    'state_label' => $this->stateLabel($status),
                    'connection_state' => $primary->connection_state,
                    'connection_label' => $this->stateLabel($primary->connection_state),
                    'state_since' => $this->iso($statusSince),
                    'state_duration_seconds' => $this->ageSeconds($statusSince, $now),
                    'connected_since' => $this->iso($primary->connected_since),
                    'connected_for_seconds' => $primary->connected_since === null ? null : $this->ageSeconds($primary->connected_since, $now),
                    'device' => $primary->device_state,
                    'device_label' => $this->stateLabel($primary->device_state),
                    'queue_status' => $primary->voice_state,
                    'queue_status_code' => $primary->status_code,
                    'queue_paused' => (bool) $primary->paused,
                    'queue' => collect($queues)->pluck('name')->implode(', '),
                    'queue_detail' => collect($queues)->pluck('external_queue_name')->implode(', '),
                    'queues' => $queues,
                    'current_activity' => $this->currentActivity($status, $pauseReason),
                    'last_seen_at' => $this->iso($primary->last_seen_at),
                    'last_update_seconds' => $this->ageSeconds($primary->last_seen_at, $now),
                    'freshness' => $this->freshnessFor($primary->last_seen_at, $now, $staleSeconds, $offlineSeconds),
                ];
            })
            ->reject(fn (array $agent): bool => ($agent['status'] ?? null) === 'offline')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<string, AgentSession>
     */
    private function agentSessionsFor(Collection $rows, ?int $tenantId): Collection
    {
        $userIds = $this->userIdsFor($rows);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = AgentSession::withoutGlobalScopes()
            ->with(['pauseReason' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereIn('user_id', $userIds);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->get()
            ->keyBy(fn (AgentSession $session): string => $this->agentSessionKey((int) $session->tenant_id, (int) $session->user_id));
    }

    private function agentSessionForState(RealtimeVoiceAgentState $state, Collection $agentSessions): ?AgentSession
    {
        if ($state->user_id === null) {
            return null;
        }

        $session = $agentSessions->get($this->agentSessionKey((int) $state->tenant_id, (int) $state->user_id));

        return $session instanceof AgentSession ? $session : null;
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<string, VoiceSession>
     */
    private function voiceSessionsFor(Collection $rows, ?int $tenantId): Collection
    {
        $userIds = $this->userIdsFor($rows);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $query = VoiceSession::withoutGlobalScopes()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', ['registered', 'active'])
            ->whereNull('revoked_at')
            ->whereNull('replaced_at')
            ->whereNull('failed_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (VoiceSession $session): string => $this->agentSessionKey((int) $session->tenant_id, (int) $session->user_id))
            ->keyBy(fn (VoiceSession $session): string => $this->agentSessionKey((int) $session->tenant_id, (int) $session->user_id));
    }

    private function voiceSessionForState(RealtimeVoiceAgentState $state, Collection $voiceSessions): ?VoiceSession
    {
        if ($state->user_id === null) {
            return null;
        }

        $session = $voiceSessions->get($this->agentSessionKey((int) $state->tenant_id, (int) $state->user_id));

        return $session instanceof VoiceSession ? $session : null;
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<int, int>
     */
    private function userIdsFor(Collection $rows): Collection
    {
        return $rows
            ->pluck('user_id')
            ->filter()
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();
    }

    private function agentSessionKey(int $tenantId, int $userId): string
    {
        return $tenantId . ':' . $userId;
    }

    private function agentIdentity(RealtimeVoiceAgentState $state): string
    {
        if ($state->user_id !== null) {
            return 'user:' . $state->user_id;
        }

        $fallback = $state->extension ?: $state->member_name ?: $state->interface;

        return 'member:' . $state->tenant_id . ':' . $fallback;
    }

    private function operationalStatus(RealtimeVoiceAgentState $state, ?AgentSession $agentSession = null, ?VoiceCall $activeCall = null): string
    {
        if ($activeCall?->direction === 'outbound') {
            return 'outbound_call';
        }

        if ($activeCall?->direction === 'inbound' && $this->isAnsweredActiveCall($activeCall)) {
            return 'on_call';
        }

        if ($activeCall?->direction === 'inbound') {
            return 'ringing';
        }

        if ($agentSession?->status === 'wrap_up') {
            return 'wrap_up';
        }

        if ($agentSession?->status === 'offline') {
            return 'offline';
        }

        if ($agentSession?->status === 'paused' || $state->paused) {
            return 'paused';
        }

        if ($state->in_call) {
            return 'on_call';
        }

        if (in_array((int) $state->status_code, [6, 7], true)) {
            return 'ringing';
        }

        return match ((int) $state->status_code) {
            1 => 'ready',
            2, 3 => 'busy',
            4, 5 => 'unavailable',
            default => 'not_ready',
        };
    }

    /**
     * @return array{timestamp: mixed, source: string, raw: ?string}
     */
    private function statusSinceDetails(RealtimeVoiceAgentState $state, ?AgentSession $agentSession, string $status, ?VoiceCall $activeCall, Collection $wrapUpCalls, Collection $wrapUpLogs, CarbonImmutable $now): array
    {
        if ($status === 'outbound_call' && $activeCall !== null) {
            return $this->statusSinceResult($activeCall->answered_at ?? $activeCall->ringing_at ?? $activeCall->created_at ?? $state->state_since, 'voice_calls.active_outbound', null);
        }

        if ($status === 'on_call' && $activeCall !== null) {
            return $this->statusSinceResult($activeCall->answered_at ?? $state->state_since, 'voice_calls.answered_at', null);
        }

        if ($status === 'ringing' && $activeCall !== null) {
            return $this->statusSinceResult($activeCall->ringing_at ?? $activeCall->created_at ?? $state->state_since, 'voice_calls.ringing_at', null);
        }

        if ($status === 'wrap_up') {
            if ($agentSession?->status === 'wrap_up') {
                $timestamp = $this->storedUtcTimestamp($agentSession, 'status_since', $now);
                if ($timestamp !== null) {
                    return $this->statusSinceResult($timestamp, 'agent_sessions.status_since', $this->rawTimestamp($agentSession, 'status_since'));
                }
            }

            $wrapUpCall = $this->wrapUpCallForState($state, $wrapUpCalls);
            if ($wrapUpCall !== null) {
                $wrapupStartedAt = $this->storedUtcTimestamp($wrapUpCall, 'wrapup_started_at', $now);
                if ($wrapupStartedAt !== null) {
                    return $this->statusSinceResult($wrapupStartedAt, 'voice_calls.wrapup_started_at', $this->rawTimestamp($wrapUpCall, 'wrapup_started_at'));
                }
            }

            $wrapUpLog = $this->wrapUpLogForState($state, $wrapUpLogs);
            if ($wrapUpLog !== null) {
                $startedAt = $this->storedUtcTimestamp($wrapUpLog, 'started_at', $now);
                if ($startedAt !== null) {
                    return $this->statusSinceResult($startedAt, 'agent_status_logs.started_at', $this->rawTimestamp($wrapUpLog, 'started_at'));
                }
            }

            if ($wrapUpCall !== null) {
                $endedAt = $this->storedUtcTimestamp($wrapUpCall, 'ended_at', $now);
                if ($endedAt !== null) {
                    return $this->statusSinceResult($endedAt, 'voice_calls.ended_at', $this->rawTimestamp($wrapUpCall, 'ended_at'));
                }
            }
        }

        if ($status === 'paused' && $agentSession?->status === 'paused') {
            $timestamp = $this->storedUtcTimestamp($agentSession, 'status_since', $now);
            if ($timestamp !== null) {
                return $this->statusSinceResult($timestamp, 'agent_sessions.status_since', $this->rawTimestamp($agentSession, 'status_since'));
            }
        }

        if ($status === 'offline' && $agentSession?->status === 'offline') {
            $timestamp = $this->storedUtcTimestamp($agentSession, 'status_since', $now);
            if ($timestamp !== null) {
                return $this->statusSinceResult($timestamp, 'agent_sessions.status_since', $this->rawTimestamp($agentSession, 'status_since'));
            }
        }

        if ($status === 'ready' && $agentSession?->status === 'ready') {
            $timestamp = $this->storedUtcTimestamp($agentSession, 'status_since', $now);
            if ($timestamp !== null) {
                return $this->statusSinceResult($timestamp, 'agent_sessions.status_since', $this->rawTimestamp($agentSession, 'status_since'));
            }
        }

        return $this->statusSinceResult($state->state_since, 'realtime_voice_agent_states.state_since', null);
    }

    /**
     * @return array{timestamp: mixed, source: string, raw: ?string}
     */
    private function statusSinceResult(mixed $timestamp, string $source, ?string $raw): array
    {
        return ['timestamp' => $timestamp, 'source' => $source, 'raw' => $raw];
    }

    private function pauseReason(RealtimeVoiceAgentState $state, ?AgentSession $agentSession, string $status): ?string
    {
        if ($status !== 'paused') {
            return null;
        }

        if ($agentSession?->status === 'paused' && $agentSession->pauseReason?->name) {
            return $agentSession->pauseReason->name;
        }

        return $state->pause_reason ?: null;
    }

    private function currentActivity(string $status, ?string $pauseReason = null): string
    {
        return match ($status) {
            'on_call' => 'Call in progress',
            'outbound_call' => 'Outbound call in progress',
            'ringing' => 'Incoming call',
            'wrap_up' => 'After Call Work',
            'paused' => $pauseReason ?: 'Paused',
            'offline' => 'Offline',
            'ready' => 'Waiting for call',
            'unavailable' => 'Unavailable',
            'busy' => 'Busy',
            'unknown' => 'Unknown',
            default => 'Not ready',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function waitingCallRows(?int $tenantId, CarbonImmutable $now, array $filters): array
    {
        $query = RealtimeVoiceWaitingCall::withoutGlobalScopes()
            ->with('omniQueue:id,name')
            ->where('status', 'waiting');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($filters['queue_id'] !== null) {
            $query->where('omni_queue_id', $filters['queue_id']);
        } elseif ($filters['campaign_id'] !== null) {
            $query->whereHas('omniQueue', function (Builder $query) use ($filters): void {
                $query->withoutGlobalScopes()->where('campaign_id', $filters['campaign_id']);
            });
        }

        if ($filters['authorized'] === false) {
            $query->whereRaw('1 = 0');
        }

        return $query
            ->orderBy('position')
            ->orderBy('waiting_since')
            ->get()
            ->map(function (RealtimeVoiceWaitingCall $call) use ($now): array {
                $waitingSince = $this->storedUtcTimestamp($call, 'waiting_since', $now);

                return [
                    'interaction_key' => $call->interaction_key,
                    'ani' => $this->displayNanp($call->caller_number),
                    'ani_original' => $call->caller_number,
                    'queue' => $call->omniQueue?->name ?: $call->external_queue_name,
                    'queue_detail' => $call->external_queue_name,
                    'position' => $call->position,
                    'priority' => $call->priority,
                    'waiting_since' => $this->iso($waitingSince),
                    'waiting_since_epoch' => $this->epoch($waitingSince),
                    'waiting_seconds' => $this->ageSeconds($waitingSince, $now),
                    'status' => $call->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @param array<int, array<string, mixed>> $waitingCalls
     * @return array<string, int>
     */
    private function kpis(array $agents, array $waitingCalls): array
    {
        $counts = collect($agents)->countBy('status');

        return [
            'ready' => (int) ($counts['ready'] ?? 0),
            'on_call' => (int) ($counts['on_call'] ?? 0),
            'outbound_call' => (int) ($counts['outbound_call'] ?? 0),
            'ringing' => (int) ($counts['ringing'] ?? 0),
            'wrap_up' => (int) ($counts['wrap_up'] ?? 0),
            'paused' => (int) ($counts['paused'] ?? 0),
            'busy' => (int) ($counts['busy'] ?? 0),
            'unavailable' => (int) ($counts['unavailable'] ?? 0),
            'waiting_calls' => count($waitingCalls),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotFreshness(CarbonImmutable $now, int $staleSeconds, int $offlineSeconds): array
    {
        $snapshot = RealtimePbxSnapshot::query()
            ->where('status', 'processed')
            ->orderByDesc('received_at')
            ->first();

        $lastSeen = $snapshot === null ? null : $this->snapshotReceivedAt($snapshot, $now);
        $age = $this->snapshotAgeSeconds($lastSeen, $now);
        $reportedAge = $age;
        $status = 'offline';

        if ($age !== null) {
            if ($age < -self::SNAPSHOT_FUTURE_TOLERANCE_SECONDS) {
                Log::warning('Realtime voice monitor snapshot timestamp is in the future.', [
                    'pbx_id' => $snapshot?->pbx_id,
                    'sequence' => $snapshot?->sequence,
                    'last_snapshot_at' => $lastSeen?->toIso8601String(),
                    'generated_at' => $now->toIso8601String(),
                    'age_seconds' => $age,
                ]);

                $status = 'delayed';
            } else {
                $reportedAge = max(0, $age);
                $status = $reportedAge > $offlineSeconds ? 'offline' : ($reportedAge > $staleSeconds ? 'delayed' : 'live');
            }
        }

        return [
            'last_snapshot_at' => $this->iso($lastSeen),
            'last_snapshot_epoch' => $this->epoch($lastSeen),
            'age_seconds' => $reportedAge,
            'status' => $status,
            'is_stale' => $status !== 'live',
        ];
    }

    private function snapshotReceivedAt(RealtimePbxSnapshot $snapshot, CarbonImmutable $now): ?CarbonImmutable
    {
        return $this->storedUtcTimestamp($snapshot, 'received_at', $now);
    }

    private function storedUtcTimestamp(Model $model, string $field, CarbonImmutable $now): ?CarbonImmutable
    {
        $raw = $model->getRawOriginal($field);

        if (is_string($raw) && trim($raw) !== '') {
            return $this->parseStoredSnapshotTimestamp($raw, $now);
        }

        $value = $model->getAttribute($field);

        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    private function rawTimestamp(Model $model, string $field): ?string
    {
        $raw = $model->getRawOriginal($field);

        if ($raw === null || $raw === '') {
            return null;
        }

        return is_string($raw) ? $raw : (string) $raw;
    }

    private function parseStoredSnapshotTimestamp(string $raw, CarbonImmutable $now): ?CarbonImmutable
    {
        $raw = trim($raw);
        $appTimezone = (string) config('app.timezone', 'UTC');
        $candidates = [];

        $this->addSnapshotTimestampCandidate($candidates, $raw, 'UTC', $now);

        if (strcasecmp($appTimezone, 'UTC') !== 0) {
            $this->addSnapshotTimestampCandidate($candidates, $raw, $appTimezone, $now);
        }

        try {
            $parsed = CarbonImmutable::parse($raw)->utc();
            $candidates[$parsed->getTimestamp()] = $parsed;
        } catch (\Throwable) {
            // Ignore invalid database values; callers handle null freshness.
        }

        if ($candidates === []) {
            return null;
        }

        uasort(
            $candidates,
            fn (CarbonImmutable $a, CarbonImmutable $b): int => abs($now->getTimestamp() - $a->getTimestamp()) <=> abs($now->getTimestamp() - $b->getTimestamp())
        );

        return reset($candidates) ?: null;
    }

    /**
     * @param array<int, CarbonImmutable> $candidates
     */
    private function addSnapshotTimestampCandidate(array &$candidates, string $raw, string $timezone, CarbonImmutable $now): void
    {
        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i:s.u'];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $raw, $timezone);
            } catch (\Throwable) {
                $parsed = false;
            }

            if ($parsed instanceof CarbonImmutable) {
                $utc = $parsed->utc();
                $candidates[$utc->getTimestamp()] = $utc;

                return;
            }
        }

        try {
            $parsed = CarbonImmutable::parse($raw, $timezone)->utc();
            $candidates[$parsed->getTimestamp()] = $parsed;
        } catch (\Throwable) {
            Log::warning('Realtime voice monitor could not parse snapshot timestamp.', [
                'timezone' => $timezone,
                'age_reference' => $now->toIso8601String(),
            ]);
        }
    }

    private function snapshotAgeSeconds(?CarbonImmutable $timestamp, CarbonImmutable $now): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return $now->getTimestamp() - $timestamp->getTimestamp();
    }

    private function freshnessFor(mixed $timestamp, CarbonImmutable $now, int $staleSeconds, int $offlineSeconds): string
    {
        $age = $this->ageSeconds($timestamp, $now);

        if ($age === null || $age > $offlineSeconds) {
            return 'offline';
        }

        return $age > $staleSeconds ? 'stale' : 'fresh';
    }

    private function ageSeconds(mixed $timestamp, CarbonImmutable $now): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return (int) max(0, CarbonImmutable::parse($timestamp)->diffInSeconds($now));
    }

    private function iso(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->utc()->toIso8601String();
    }

    private function stateLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Unknown';
        }

        if ($value === 'wrap_up') {
            return 'Wrap-Up';
        }

        if ($value === 'outbound_call') {
            return 'Outbound Call';
        }

        return str_replace(' ', ' ', ucwords(str_replace('_', ' ', $value)));
    }

    private function epoch(mixed $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->utc()->getTimestamp();
    }

    private function isAnsweredActiveCall(VoiceCall $call): bool
    {
        return $call->answered_at !== null || in_array($call->call_state, ['active', 'held'], true);
    }

    private function activeCallForState(RealtimeVoiceAgentState $state, Collection $activeCalls): ?VoiceCall
    {
        if ($state->user_id === null) {
            return null;
        }

        $call = $activeCalls->get($this->agentSessionKey((int) $state->tenant_id, (int) $state->user_id));

        return $call instanceof VoiceCall ? $call : null;
    }

    private function wrapUpCallForState(RealtimeVoiceAgentState $state, Collection $wrapUpCalls): ?VoiceCall
    {
        if ($state->user_id === null) {
            return null;
        }

        $call = $wrapUpCalls->get($this->agentSessionKey((int) $state->tenant_id, (int) $state->user_id));

        return $call instanceof VoiceCall ? $call : null;
    }

    private function needsWrapUpStatusLogs(Collection $rows, Collection $agentSessions, Collection $wrapUpCalls, CarbonImmutable $now): bool
    {
        foreach ($rows as $state) {
            if (! $state instanceof RealtimeVoiceAgentState || $state->user_id === null) {
                continue;
            }

            $agentSession = $this->agentSessionForState($state, $agentSessions);
            if ($agentSession?->status !== 'wrap_up') {
                continue;
            }

            if ($this->storedUtcTimestamp($agentSession, 'status_since', $now) !== null) {
                continue;
            }

            $wrapUpCall = $this->wrapUpCallForState($state, $wrapUpCalls);
            if ($wrapUpCall !== null && $this->storedUtcTimestamp($wrapUpCall, 'wrapup_started_at', $now) !== null) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function wrapUpLogForState(RealtimeVoiceAgentState $state, Collection $wrapUpLogs): ?AgentStatusLog
    {
        if ($state->user_id === null) {
            return null;
        }

        $log = $wrapUpLogs->get($this->agentSessionKey((int) $state->tenant_id, (int) $state->user_id));

        return $log instanceof AgentStatusLog ? $log : null;
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<string, VoiceCall>
     */
    private function activeVoiceCallsFor(Collection $rows, ?int $tenantId): Collection
    {
        $userIds = $this->userIdsFor($rows);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = VoiceCall::withoutGlobalScopes()
            ->whereIn('agent_user_id', $userIds)
            ->whereIn('call_state', VoiceCall::ACTIVE_STATES);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->orderByRaw("case when direction = 'outbound' then 3 when answered_at is not null then 2 else 1 end desc")
            ->orderByDesc('answered_at')
            ->orderByDesc('ringing_at')
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (VoiceCall $call): string => $this->agentSessionKey((int) $call->tenant_id, (int) $call->agent_user_id))
            ->keyBy(fn (VoiceCall $call): string => $this->agentSessionKey((int) $call->tenant_id, (int) $call->agent_user_id));
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<string, VoiceCall>
     */
    private function wrapUpVoiceCallsFor(Collection $rows, ?int $tenantId): Collection
    {
        $userIds = $this->userIdsFor($rows);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = VoiceCall::withoutGlobalScopes()
            ->whereIn('agent_user_id', $userIds)
            ->whereNotIn('call_state', VoiceCall::ACTIVE_STATES)
            ->where(function (Builder $query): void {
                $query->whereNotNull('wrapup_started_at')
                    ->orWhereNotNull('ended_at');
            });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->orderByDesc('wrapup_started_at')
            ->orderByDesc('ended_at')
            ->orderByDesc('updated_at')
            ->get()
            ->unique(fn (VoiceCall $call): string => $this->agentSessionKey((int) $call->tenant_id, (int) $call->agent_user_id))
            ->keyBy(fn (VoiceCall $call): string => $this->agentSessionKey((int) $call->tenant_id, (int) $call->agent_user_id));
    }

    /**
     * @param Collection<int, RealtimeVoiceAgentState> $rows
     * @return Collection<string, AgentStatusLog>
     */
    private function wrapUpStatusLogsFor(Collection $rows, ?int $tenantId): Collection
    {
        $userIds = $this->userIdsFor($rows);

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = AgentStatusLog::withoutGlobalScopes()
            ->whereIn('user_id', $userIds)
            ->where('status', 'wrap_up')
            ->whereNull('ended_at');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (AgentStatusLog $log): string => $this->agentSessionKey((int) $log->tenant_id, (int) $log->user_id))
            ->keyBy(fn (AgentStatusLog $log): string => $this->agentSessionKey((int) $log->tenant_id, (int) $log->user_id));
    }

    /**
     * @return array{campaign_id: ?int, queue_id: ?int, authorized: bool}
     */
    private function resolveFilters(?int $tenantId, array $filters): array
    {
        $campaignId = $this->positiveInt($filters['campaign_id'] ?? null);
        $queueId = $this->positiveInt($filters['queue_id'] ?? null);

        if ($queueId !== null) {
            $queue = OmniQueue::withoutGlobalScopes()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('id', $queueId)
                ->first(['id', 'campaign_id']);

            if ($queue === null) {
                return ['campaign_id' => null, 'queue_id' => null, 'authorized' => false];
            }

            if ($campaignId !== null && (int) $queue->campaign_id !== $campaignId) {
                return ['campaign_id' => null, 'queue_id' => null, 'authorized' => false];
            }

            return ['campaign_id' => $campaignId, 'queue_id' => $queueId, 'authorized' => true];
        }

        if ($campaignId !== null) {
            $exists = OmniQueue::withoutGlobalScopes()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('campaign_id', $campaignId)
                ->exists();

            if (!$exists) {
                return ['campaign_id' => null, 'queue_id' => null, 'authorized' => false];
            }
        }

        return ['campaign_id' => $campaignId, 'queue_id' => null, 'authorized' => true];
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : (int) $int;
    }

    private function displayNanp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return $digits;
        }

        return trim(strip_tags($value));
    }
}
