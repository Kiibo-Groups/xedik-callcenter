<?php

declare(strict_types=1);

namespace App\Services\OmniQueue;

use App\Models\OmniQueue;
use App\Models\User;
use App\Models\VoiceExtension;
use App\Services\Voice\AsteriskQueueMemberRealtimeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OmniQueueAsteriskMemberSyncService
{
    public function __construct(
        private readonly AsteriskQueueMemberRealtimeService $members
    ) {}

    public function syncQueueMembers(OmniQueue $queue): array
    {
        $queue = $queue->fresh(['voiceSettings']);
        if (!$queue) {
            return $this->emptyResult();
        }

        try {
            return $this->syncQueueMembersUnsafe($queue);
        } catch (Throwable $e) {
            Log::warning('[OmniQueueAsteriskMembers] synchronization failure', [
                'omni_queue_id' => $queue->id,
                'tenant_id' => $queue->tenant_id,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 300),
            ]);

            return $this->emptyResult(['failed' => 1]);
        }
    }

    public function syncUserQueues(User $user): void
    {
        $queues = $user->omniQueues()
            ->with('voiceSettings')
            ->get();

        foreach ($queues as $queue) {
            $this->syncQueueMembers($queue);
        }
    }

    public function deleteQueueMembersForName(?string $queueName, ?int $queueId = null, ?int $tenantId = null): array
    {
        if (blank($queueName)) {
            return $this->emptyResult();
        }

        try {
            $removed = $this->members->removeQueue((string) $queueName);

            Log::info('[OmniQueueAsteriskMembers] queue members removed after queue deletion', [
                'omni_queue_id' => $queueId,
                'tenant_id' => $tenantId,
                'queue_name' => $queueName,
                'removed' => $removed,
            ]);

            return $this->emptyResult(['removed' => $removed]);
        } catch (Throwable $e) {
            Log::warning('[OmniQueueAsteriskMembers] deletion cleanup failure', [
                'omni_queue_id' => $queueId,
                'tenant_id' => $tenantId,
                'queue_name' => $queueName,
                'error_class' => $e::class,
                'message' => Str::limit($e->getMessage(), 300),
            ]);

            return $this->emptyResult(['failed' => 1]);
        }
    }

    private function syncQueueMembersUnsafe(OmniQueue $queue): array
    {
        $result = $this->emptyResult();
        $settings = $queue->voiceSettings;
        $queueName = (string) ($settings?->asterisk_queue_name ?? '');

        Log::info('[OmniQueueAsteriskMembers] sync started', [
            'omni_queue_id' => $queue->id,
            'tenant_id' => $queue->tenant_id,
            'queue_name' => $queueName,
        ]);

        if (blank($queueName)) {
            return $result;
        }

        if (!$this->queueShouldHaveMembers($queue)) {
            $removed = $this->members->removeQueue($queueName);

            return $this->withCounts($result, [
                'removed' => $removed,
            ]);
        }

        $desired = $this->desiredMembers($queue);
        $result['skipped'] += $desired['skipped'];
        $desiredByInterface = $desired['members'];

        Log::info('[OmniQueueAsteriskMembers] desired member count calculated', [
            'omni_queue_id' => $queue->id,
            'tenant_id' => $queue->tenant_id,
            'queue_name' => $queueName,
            'desired' => count($desiredByInterface),
            'skipped' => $result['skipped'],
        ]);

        foreach ($desiredByInterface as $interface => $attributes) {
            $upserted = $this->members->upsertConfiguration($attributes);
            $result['created'] += $upserted['created'];
            $result['updated'] += $upserted['updated'];
            $result['duplicates_removed'] += $upserted['duplicates_removed'];
        }

        $currentRows = $this->members->rowsForQueue($queueName);
        foreach ($currentRows as $row) {
            $interface = (string) $row->interface;

            if (!array_key_exists($interface, $desiredByInterface)) {
                $result['removed'] += $this->members->removeById((int) $row->uniqueid);
            }
        }

        Log::info('[OmniQueueAsteriskMembers] sync completed', [
            'omni_queue_id' => $queue->id,
            'tenant_id' => $queue->tenant_id,
            'queue_name' => $queueName,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'removed' => $result['removed'],
            'duplicates_removed' => $result['duplicates_removed'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }

    private function queueShouldHaveMembers(OmniQueue $queue): bool
    {
        $settings = $queue->voiceSettings;

        return $queue->supportsVoice()
            && $settings
            && $settings->is_enabled
            && filled($settings->asterisk_queue_name)
            && $settings->sync_status === 'synced'
            && $settings->last_synced_at !== null;
    }

    private function desiredMembers(OmniQueue $queue): array
    {
        $members = [];
        $skipped = 0;

        $agents = $queue->agents()
            ->with(['role'])
            ->get();

        foreach ($agents as $agent) {
            if (!$this->assignedAgentCanBecomeAsteriskMember($queue, $agent)) {
                $skipped++;
                continue;
            }

            $extension = $this->activeVoiceExtensionFor($queue, $agent);
            $identity = $extension ? $this->identityFor($extension) : null;

            if (!$identity) {
                $skipped++;
                continue;
            }

            $members[$identity['interface']] = [
                'queue_name' => $queue->voiceSettings->asterisk_queue_name,
                'membername' => $identity['membername'],
                'interface' => $identity['interface'],
                'state_interface' => $identity['state_interface'],
                'penalty' => (int) ($agent->pivot?->priority ?? 0),
                'paused' => 1,
            ];
        }

        return ['members' => $members, 'skipped' => $skipped];
    }

    private function assignedAgentCanBecomeAsteriskMember(OmniQueue $queue, User $agent): bool
    {
        if (!(bool) ($agent->pivot?->active ?? false)) {
            return false;
        }

        if ((int) $agent->tenant_id !== (int) $queue->tenant_id) {
            return false;
        }

        if (!$agent->is_active) {
            return false;
        }

        if (!in_array($agent->role()->first()?->name, ['agent', 'supervisor'], true)) {
            return false;
        }

        if ($queue->campaign_id && !$agent->campaigns()->withoutGlobalScopes()->where('campaigns.id', $queue->campaign_id)->exists()) {
            return false;
        }

        return true;
    }

    private function activeVoiceExtensionFor(OmniQueue $queue, User $agent): ?VoiceExtension
    {
        return VoiceExtension::withoutGlobalScopes()
            ->where('tenant_id', $queue->tenant_id)
            ->where('user_id', $agent->id)
            ->where('is_active', true)
            ->first();
    }

    private function identityFor(VoiceExtension $extension): ?array
    {
        $membername = trim((string) $extension->extension);
        $endpoint = trim((string) $extension->pjsip_endpoint);

        if ($membername === '' || $endpoint === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $endpoint)) {
            return null;
        }

        $interface = 'PJSIP/' . $endpoint;

        if (strlen($membername) > 80 || strlen($interface) > 80) {
            return null;
        }

        return [
            'membername' => $membername,
            'interface' => $interface,
            'state_interface' => $interface,
        ];
    }

    private function emptyResult(array $overrides = []): array
    {
        return array_replace([
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
            'duplicates_removed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ], $overrides);
    }

    private function withCounts(array $result, array $counts): array
    {
        foreach ($counts as $key => $value) {
            $result[$key] += (int) $value;
        }

        return $result;
    }
}
