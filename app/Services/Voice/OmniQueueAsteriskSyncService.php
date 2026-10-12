<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\OmniQueue;
use App\Models\OmniQueueVoiceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class OmniQueueAsteriskSyncService
{
    public function __construct(
        private readonly AsteriskQueueRealtimeService $queues
    ) {}

    public function sync(OmniQueue $queue): OmniQueueVoiceSetting
    {
        $attemptStartedAt = now();
        $queue->loadMissing('voiceSettings');
        $settings = $queue->voiceSettings;

        $this->validateCrmState($queue, $settings);
        $attributes = $this->mapAttributes($settings);

        try {
            $this->queues->upsert($attributes);

            $row = $this->queues->find($settings->asterisk_queue_name);
            if (!$row) {
                throw new OmniQueueAsteriskSyncException('asterisk_queue_verify_failed');
            }

            $settings->forceFill([
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return $settings->fresh();
        } catch (Throwable $e) {
            $this->markFailed($settings, $e, $attemptStartedAt);

            if ($e instanceof OmniQueueAsteriskSyncException) {
                throw $e;
            }

            throw new OmniQueueAsteriskSyncException($this->safeMessage($e), previous: $e);
        }
    }

    private function validateCrmState(OmniQueue $queue, ?OmniQueueVoiceSetting $settings): void
    {
        if (!$settings) {
            throw new OmniQueueAsteriskSyncException('missing_voice_settings');
        }

        if ((int) $queue->tenant_id !== (int) $settings->tenant_id) {
            throw new OmniQueueAsteriskSyncException('tenant_mismatch');
        }

        if (!$queue->supportsVoice()) {
            throw new OmniQueueAsteriskSyncException('queue_does_not_support_voice');
        }

        if (!$queue->hasVoiceRoutingEnabled()) {
            throw new OmniQueueAsteriskSyncException('voice_routing_disabled');
        }

        if (!preg_match('/^[a-z0-9_]{1,128}$/', (string) $settings->asterisk_queue_name)) {
            throw new OmniQueueAsteriskSyncException('invalid_asterisk_queue_name');
        }
    }

    private function mapAttributes(OmniQueueVoiceSetting $settings): array
    {
        return [
            'name' => $settings->asterisk_queue_name,
            'musiconhold' => $settings->music_on_hold,
            'strategy' => $settings->strategy,
            'timeout' => $settings->timeout,
            'retry' => $settings->retry,
            'maxlen' => $settings->maxlen,
            'servicelevel' => $settings->service_level,
            'weight' => $settings->weight,
            'ringinuse' => $settings->ring_in_use,
            'wrapuptime' => $settings->wrapup_time,
            'autofill' => true,
            'autopause' => $settings->autopause,
        ];
    }

    private function markFailed(
        ?OmniQueueVoiceSetting $settings,
        Throwable $e,
        Carbon $attemptStartedAt
    ): void
    {
        if (!$settings) {
            return;
        }

        $fresh = $settings->fresh();

        if ($fresh
            && $fresh->sync_status === 'synced'
            && $fresh->last_synced_at
            && $fresh->last_synced_at->greaterThanOrEqualTo($attemptStartedAt)) {
            return;
        }

        $settings->forceFill([
            'sync_status' => 'failed',
            'sync_error' => $this->safeMessage($e),
        ])->save();
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof OmniQueueAsteriskSyncException) {
            return Str::limit($e->getMessage() ?: 'Asterisk queue sync failed.', 500, '');
        }

        return 'Asterisk queue sync failed.';
    }
}
