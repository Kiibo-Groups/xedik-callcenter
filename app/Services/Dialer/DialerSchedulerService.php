<?php

declare(strict_types=1);

namespace App\Services\Dialer;

use App\Models\DialerCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DialerSchedulerService
{
    /**
     * Start or resume a campaign (populates queue_dial if idle/scheduled, sets status to running).
     */
    public function startCampaign(DialerCampaign $campaign): array
    {
        if ($campaign->dialer_type === 'preview') {
            $campaign->update([
                'status'       => 'running',
                'started_at'   => $campaign->started_at ?? now(),
                'completed_at' => null,
            ]);
            Log::info("Preview DialerCampaign #{$campaign->id} started/resumed.");
            return ['ok' => true, 'type' => 'preview'];
        }

        $leadList = $campaign->leadList;
        if (!$leadList) {
            return ['ok' => false, 'error' => 'Campaign has no lead list assigned.'];
        }
        if ($leadList->status !== 'ready') {
            return ['ok' => false, 'error' => 'Lead list is not in ready status.'];
        }

        // Resuming from paused
        if ($campaign->isPaused()) {
            $pending = DB::table('queue_dial')
                ->where('dialer_campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->count();

            $campaign->update(['status' => 'running', 'completed_at' => null]);
            Log::info("DialerCampaign #{$campaign->id} resumed: {$pending} pending leads in queue.");
            return ['ok' => true, 'enqueued' => $pending, 'resumed' => true];
        }

        // Starting from idle or scheduled: populate queue_dial
        $campaign->update([
            'status'       => 'running',
            'started_at'   => $campaign->started_at ?? now(),
            'completed_at' => null,
        ]);

        $tenantId  = (int) $campaign->tenant_id;
        $tableName = $leadList->table_name;

        $dncPhones = DB::table('dialer_dnc')
            ->where('tenant_id', $tenantId)
            ->pluck('phone')
            ->toArray();

        $leads = DB::table($tableName)
            ->whereIn('status', ['NEW', 'BUSY', 'NO_ANSWER', 'FAILED'])
            ->where('called_count', '<', $campaign->max_retries)
            ->when(!empty($dncPhones), fn($q) => $q->whereNotIn('phone', $dncPhones))
            ->select('id', 'phone')
            ->get();

        if ($leads->isEmpty()) {
            $campaign->update(['status' => 'completed', 'completed_at' => now()]);
            return ['ok' => true, 'enqueued' => 0, 'message' => 'No eligible leads — campaign marked as completed.'];
        }

        $rows = $leads->map(fn($l) => [
            'tenant_id'          => $tenantId,
            'dialer_campaign_id' => $campaign->id,
            'lead_list_table'    => $tableName,
            'dialer_lead_id'     => $l->id,
            'phone'              => $l->phone,
            'status'             => 'pending',
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('queue_dial')->insert($chunk);
        }

        Log::info("DialerCampaign #{$campaign->id} started: {$leads->count()} leads queued.");
        return ['ok' => true, 'enqueued' => $leads->count()];
    }

    /**
     * Check scheduled campaigns that are ready to run (scheduled_at <= now()) and launch them.
     */
    public function processScheduledCampaigns(): int
    {
        $due = DialerCampaign::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $started = 0;
        foreach ($due as $campaign) {
            Log::info("Launching scheduled DialerCampaign #{$campaign->id} ('{$campaign->name}') automatically at " . now()->toDateTimeString());
            $res = $this->startCampaign($campaign);
            if ($res['ok'] ?? false) {
                $started++;
            } else {
                Log::warning("Failed to launch scheduled campaign #{$campaign->id}: " . ($res['error'] ?? 'Unknown error'));
            }
        }

        return $started;
    }

    /**
     * Auto-pause campaigns that reached scheduled_end_at or are outside daily business hours.
     * Auto-resume campaigns that are in paused state if their daily window opens.
     */
    public function checkDailyWindowsAndAutoPause(): array
    {
        $paused = 0;
        $resumed = 0;
        $now = now();
        $currentTime = $now->format('H:i:s');

        // 1. Check campaigns with reached scheduled_end_at
        $expiredRunning = DialerCampaign::withoutGlobalScopes()
            ->where('status', 'running')
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', $now)
            ->get();

        foreach ($expiredRunning as $campaign) {
            $campaign->update(['status' => 'paused']);
            Log::info("DialerCampaign #{$campaign->id} auto-paused: reached scheduled_end_at {$campaign->scheduled_end_at}");
            $paused++;
        }

        // 2. Check daily business hour window (daily_start_time / daily_end_time)
        $dailyCampaigns = DialerCampaign::withoutGlobalScopes()
            ->whereNotNull('daily_start_time')
            ->whereNotNull('daily_end_time')
            ->whereIn('status', ['running', 'paused'])
            ->get();

        foreach ($dailyCampaigns as $c) {
            $start = (string) $c->daily_start_time;
            $end = (string) $c->daily_end_time;

            $isInsideWindow = ($start <= $end)
                ? ($currentTime >= $start && $currentTime <= $end)
                : ($currentTime >= $start || $currentTime <= $end);

            if ($c->isRunning() && !$isInsideWindow) {
                // Out of hours: auto-pause
                $c->update(['status' => 'paused']);
                Log::info("DialerCampaign #{$c->id} auto-paused: current time {$currentTime} is outside daily window {$start} - {$end}");
                $paused++;
            } elseif ($c->isPaused() && $isInsideWindow) {
                // If campaign has pending leads and was paused for window, auto-resume
                $hasPending = DB::table('queue_dial')
                    ->where('dialer_campaign_id', $c->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($hasPending) {
                    $c->update(['status' => 'running']);
                    Log::info("DialerCampaign #{$c->id} auto-resumed: daily window {$start} - {$end} is active.");
                    $resumed++;
                }
            }
        }

        return ['paused' => $paused, 'resumed' => $resumed];
    }
}
