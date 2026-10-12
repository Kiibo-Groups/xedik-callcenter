<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\OmniQueueVoiceSetting;
use App\Models\RealtimePbxSnapshot;
use App\Models\RealtimeVoiceAgentState;
use App\Models\RealtimeVoiceQueueState;
use App\Models\RealtimeVoiceWaitingCall;
use App\Models\User;
use App\Models\VoiceExtension;
use App\Models\VoiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RealtimeVoiceSnapshotImportService
{
    /**
     * @param array<string, mixed> $payload
     * @return array{queues:int,members:int,waiting_calls:int}
     */
    public function import(array $payload, string $rawBody): array
    {
        $started = microtime(true);
        $now = CarbonImmutable::now('UTC');
        $pbxId = (int) $payload['pbx_id'];
        $sequence = (int) $payload['sequence'];
        $payloadHash = hash('sha256', $rawBody);

        return DB::transaction(function () use ($payload, $pbxId, $sequence, $payloadHash, $started, $now): array {
            $queueMap = $this->queueMap($payload);
            $memberStatesByQueue = [];
            $processedMembers = 0;

            foreach (($payload['members'] ?? []) as $member) {
                $queueName = (string) ($member['queue'] ?? '');
                $queue = $queueMap[$queueName] ?? null;

                if ($queue === null) {
                    Log::info('realtime_monitor.member_unmapped_queue', [
                        'pbx_id' => $pbxId,
                        'queue' => $queueName,
                        'member_name' => $member['member_name'] ?? null,
                    ]);
                    continue;
                }

                $state = $this->upsertMember($pbxId, $queue, $member, $now);
                $memberStatesByQueue[$queueName][] = $state;
                $processedMembers++;
            }

            $processedWaiting = $this->upsertWaitingCalls($pbxId, $queueMap, $payload['waiting_calls'] ?? [], $now);
            $processedQueues = 0;

            foreach (($payload['queues'] ?? []) as $queuePayload) {
                $queueName = (string) ($queuePayload['queue'] ?? '');
                $queue = $queueMap[$queueName] ?? null;

                if ($queue === null) {
                    Log::info('realtime_monitor.queue_unmapped', [
                        'pbx_id' => $pbxId,
                        'queue' => $queueName,
                    ]);
                    continue;
                }

                $this->upsertQueue($pbxId, $queue, $queuePayload, $memberStatesByQueue[$queueName] ?? [], $now);
                $processedQueues++;
            }

            RealtimePbxSnapshot::query()->updateOrCreate(
                ['pbx_id' => $pbxId, 'sequence' => $sequence],
                [
                    'generated_at' => CarbonImmutable::parse((string) $payload['generated_at'])->utc(),
                    'received_at' => $now,
                    'queues_count' => $processedQueues,
                    'members_count' => $processedMembers,
                    'waiting_calls_count' => $processedWaiting,
                    'status' => 'processed',
                    'processing_ms' => (int) round((microtime(true) - $started) * 1000),
                    'payload_hash' => $payloadHash,
                    'error_message' => null,
                ]
            );

            return [
                'queues' => $processedQueues,
                'members' => $processedMembers,
                'waiting_calls' => $processedWaiting,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array{tenant_id:int,omni_queue_id:int,external_queue_name:string}>
     */
    private function queueMap(array $payload): array
    {
        $queueNames = collect([
            ...array_column($payload['queues'] ?? [], 'queue'),
            ...array_column($payload['members'] ?? [], 'queue'),
            ...array_column($payload['waiting_calls'] ?? [], 'queue'),
        ])
            ->filter(fn ($queue): bool => is_string($queue) && $queue !== '')
            ->unique()
            ->values();

        if ($queueNames->isEmpty()) {
            return [];
        }

        return OmniQueueVoiceSetting::withoutGlobalScopes()
            ->with('omniQueue:id,tenant_id')
            ->whereIn('asterisk_queue_name', $queueNames->all())
            ->get()
            ->filter(fn (OmniQueueVoiceSetting $setting): bool => $setting->omniQueue !== null
                && (int) $setting->tenant_id === (int) $setting->omniQueue->tenant_id)
            ->mapWithKeys(fn (OmniQueueVoiceSetting $setting): array => [
                $setting->asterisk_queue_name => [
                    'tenant_id' => (int) $setting->tenant_id,
                    'omni_queue_id' => (int) $setting->omni_queue_id,
                    'external_queue_name' => $setting->asterisk_queue_name,
                ],
            ])
            ->all();
    }

    /**
     * @param array{tenant_id:int,omni_queue_id:int,external_queue_name:string} $queue
     * @param array<string, mixed> $member
     */
    private function upsertMember(int $pbxId, array $queue, array $member, CarbonImmutable $now): RealtimeVoiceAgentState
    {
        $identity = $this->resolveAgent($queue['tenant_id'], $member);
        $session = $this->activeVoiceSession($queue['tenant_id'], $identity['user_id']);
        $statusCode = (int) ($member['status_code'] ?? 0);
        $deviceState = $this->deviceState($statusCode);
        $connectionState = $session === null ? 'offline' : 'connected';
        $voiceState = $this->voiceState($statusCode, (bool) ($member['paused'] ?? false), (bool) ($member['in_call'] ?? false));

        $lookup = [
            'pbx_id' => $pbxId,
            'tenant_id' => $queue['tenant_id'],
            'external_queue_name' => $queue['external_queue_name'],
            'member_name' => (string) ($member['member_name'] ?? ''),
            'interface' => (string) ($member['interface'] ?? ''),
        ];

        $existing = RealtimeVoiceAgentState::withoutGlobalScopes()
            ->where($lookup)
            ->first();

        $stateSince = $now;
        if ($existing !== null && $existing->voice_state === $voiceState) {
            $stateSince = $existing->getRawOriginal('state_since');
        }

        return RealtimeVoiceAgentState::withoutGlobalScopes()->updateOrCreate(
            $lookup,
            [
                'user_id' => $identity['user_id'],
                'omni_queue_id' => $queue['omni_queue_id'],
                'extension' => $identity['extension'],
                'state_interface' => $member['state_interface'] ?? null,
                'status_code' => $statusCode,
                'in_call' => (bool) ($member['in_call'] ?? false),
                'paused' => (bool) ($member['paused'] ?? false),
                'pause_reason' => $this->nullableString($member['pause_reason'] ?? null),
                'connection_state' => $connectionState,
                'voice_state' => $voiceState,
                'device_state' => $deviceState,
                'state_since' => $stateSince,
                'connected_since' => $session?->registered_at ?? $session?->created_at,
                'calls_taken' => (int) ($member['calls_taken'] ?? 0),
                'last_call_at' => $this->optionalTimestamp($member['last_call'] ?? null),
                'last_pause_at' => $this->optionalTimestamp($member['last_pause'] ?? null),
                'login_time_at' => $this->optionalTimestamp($member['login_time'] ?? null),
                'last_seen_at' => $now,
                'payload' => $member,
            ]
        );
    }

    /**
     * @param array{tenant_id:int,omni_queue_id:int,external_queue_name:string} $queue
     * @param array<string, mixed> $queuePayload
     * @param array<int, RealtimeVoiceAgentState> $memberStates
     */
    private function upsertQueue(int $pbxId, array $queue, array $queuePayload, array $memberStates, CarbonImmutable $now): void
    {
        RealtimeVoiceQueueState::withoutGlobalScopes()->updateOrCreate(
            [
                'pbx_id' => $pbxId,
                'tenant_id' => $queue['tenant_id'],
                'external_queue_name' => $queue['external_queue_name'],
            ],
            [
                'omni_queue_id' => $queue['omni_queue_id'],
                'calls_waiting' => (int) ($queuePayload['calls'] ?? 0),
                'hold_time' => (int) ($queuePayload['holdtime'] ?? 0),
                'talk_time' => (int) ($queuePayload['talktime'] ?? 0),
                'completed' => (int) ($queuePayload['completed'] ?? 0),
                'abandoned' => (int) ($queuePayload['abandoned'] ?? 0),
                'service_level' => (int) ($queuePayload['service_level'] ?? 0),
                'service_level_perf' => (float) ($queuePayload['service_level_perf'] ?? 0),
                'weight' => (int) ($queuePayload['weight'] ?? 0),
                'available_agents' => $this->countStates($memberStates, ['ready']),
                'busy_agents' => $this->countStates($memberStates, ['busy', 'on_call', 'ringing']),
                'paused_agents' => $this->countStates($memberStates, ['paused']),
                'unavailable_agents' => $this->countStates($memberStates, ['unavailable', 'unknown', 'not_ready']),
                'longest_wait_seconds' => $this->longestWait($queue['tenant_id'], $pbxId, $queue['external_queue_name']),
                'last_seen_at' => $now,
                'payload' => $queuePayload,
            ]
        );
    }

    /**
     * @param array<string, array{tenant_id:int,omni_queue_id:int,external_queue_name:string}> $queueMap
     * @param array<int, array<string, mixed>> $waitingCalls
     */
    private function upsertWaitingCalls(int $pbxId, array $queueMap, array $waitingCalls, CarbonImmutable $now): int
    {
        $seen = [];
        $processed = 0;

        foreach ($waitingCalls as $call) {
            $queueName = (string) ($call['queue'] ?? '');
            $queue = $queueMap[$queueName] ?? null;

            if ($queue === null) {
                Log::info('realtime_monitor.waiting_call_unmapped_queue', [
                    'pbx_id' => $pbxId,
                    'queue' => $queueName,
                    'interaction_key' => $call['interaction_key'] ?? null,
                ]);
                continue;
            }

            $interactionKey = (string) ($call['interaction_key'] ?? '');
            if ($interactionKey === '') {
                continue;
            }

            $seen[] = $interactionKey;
            $processed++;

            RealtimeVoiceWaitingCall::withoutGlobalScopes()->updateOrCreate(
                ['interaction_key' => $interactionKey],
                [
                    'tenant_id' => $queue['tenant_id'],
                    'pbx_id' => $pbxId,
                    'omni_queue_id' => $queue['omni_queue_id'],
                    'external_queue_name' => $queue['external_queue_name'],
                    'uniqueid' => (string) ($call['uniqueid'] ?? ''),
                    'channel' => (string) ($call['channel'] ?? ''),
                    'caller_number' => (string) ($call['caller_number'] ?? ''),
                    'caller_name' => $this->nullableString($call['caller_name'] ?? null),
                    'position' => (int) ($call['position'] ?? 0),
                    'priority' => (int) ($call['priority'] ?? 0),
                    'waiting_since' => CarbonImmutable::parse((string) $call['waiting_since'])->utc(),
                    'wait_seconds' => (int) ($call['wait_seconds'] ?? 0),
                    'status' => 'waiting',
                    'last_seen_at' => $now,
                    'payload' => $call,
                ]
            );
        }

        $query = RealtimeVoiceWaitingCall::withoutGlobalScopes()
            ->where('pbx_id', $pbxId)
            ->where('status', 'waiting');

        if ($seen !== []) {
            $query->whereNotIn('interaction_key', $seen);
        }

        $query->update(['status' => 'removed', 'updated_at' => $now]);

        return $processed;
    }

    /**
     * @param array<string, mixed> $member
     * @return array{user_id:?int,voice_extension_id:?int,extension:?string}
     */
    private function resolveAgent(int $tenantId, array $member): array
    {
        $endpoint = $this->endpointFromInterface($member['state_interface'] ?? null)
            ?? $this->endpointFromInterface($member['interface'] ?? null);

        $extension = null;
        $voiceExtension = null;

        if ($endpoint !== null) {
            $voiceExtension = VoiceExtension::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('pjsip_endpoint', $endpoint)
                ->first();
        }

        if ($voiceExtension !== null) {
            return [
                'user_id' => $voiceExtension->user_id === null ? null : (int) $voiceExtension->user_id,
                'voice_extension_id' => (int) $voiceExtension->id,
                'extension' => $voiceExtension->extension,
            ];
        }

        $parsed = $this->parseStableEndpoint($member['state_interface'] ?? null)
            ?? $this->parseStableEndpoint($member['interface'] ?? null);

        if ($parsed !== null && $parsed['tenant_id'] === $tenantId
            && User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $parsed['user_id'])->exists()) {
            return [
                'user_id' => $parsed['user_id'],
                'voice_extension_id' => null,
                'extension' => $parsed['extension'],
            ];
        }

        if (preg_match('/\d+/', (string) ($member['member_name'] ?? ''), $matches) === 1) {
            $extension = $matches[0];
        }

        return ['user_id' => null, 'voice_extension_id' => null, 'extension' => $extension];
    }

    private function activeVoiceSession(int $tenantId, ?int $userId): ?VoiceSession
    {
        if ($userId === null) {
            return null;
        }

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        return VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('status', ['registered', 'active'])
            ->whereNull('revoked_at')
            ->whereNull('replaced_at')
            ->whereNull('failed_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function deviceState(int $statusCode): string
    {
        return match ($statusCode) {
            1 => 'available',
            2, 3 => 'busy',
            4, 5 => 'unavailable',
            6, 7 => 'ringing',
            8 => 'held',
            default => 'unknown',
        };
    }

    private function voiceState(int $statusCode, bool $paused, bool $inCall): string
    {
        if ($inCall) {
            return 'on_call';
        }

        if (in_array($statusCode, [6, 7], true)) {
            return 'ringing';
        }

        if ($paused) {
            return 'paused';
        }

        if ($statusCode === 1) {
            return 'ready';
        }

        if (in_array($statusCode, [2, 3], true)) {
            return 'busy';
        }

        if (in_array($statusCode, [4, 5], true)) {
            return 'unavailable';
        }

        return 'unknown';
    }

    /**
     * @param array<int, RealtimeVoiceAgentState> $memberStates
     * @param array<int, string> $states
     */
    private function countStates(array $memberStates, array $states): int
    {
        return collect($memberStates)
            ->filter(fn (RealtimeVoiceAgentState $state): bool => in_array($state->voice_state, $states, true))
            ->count();
    }

    private function longestWait(int $tenantId, int $pbxId, string $queueName): int
    {
        return (int) RealtimeVoiceWaitingCall::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('pbx_id', $pbxId)
            ->where('external_queue_name', $queueName)
            ->where('status', 'waiting')
            ->max('wait_seconds');
    }

    private function endpointFromInterface(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (!str_starts_with($value, 'PJSIP/')) {
            return null;
        }

        $endpoint = substr($value, 6);
        $dash = strpos($endpoint, '-');

        return $dash === false ? $endpoint : substr($endpoint, 0, $dash);
    }

    /**
     * @return array{tenant_id:int,user_id:int,extension:string}|null
     */
    private function parseStableEndpoint(mixed $value): ?array
    {
        $endpoint = $this->endpointFromInterface($value);
        if ($endpoint === null) {
            return null;
        }

        if (preg_match('/^v(?P<tenant>\d+)_u(?P<user>\d+)_(?P<extension>[A-Za-z0-9]+)$/', $endpoint, $matches) !== 1) {
            return null;
        }

        return [
            'tenant_id' => (int) $matches['tenant'],
            'user_id' => (int) $matches['user'],
            'extension' => $matches['extension'],
        ];
    }

    private function optionalTimestamp(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
