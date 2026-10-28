<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunDialerWorker extends Command
{
    protected $signature = 'dialer:worker {--once : Run a single loop iteration and exit}';
    protected $description = 'Worker daemon to originate calls for running campaigns in queue_dial';

    public function handle(): int
    {
        $once = (bool) $this->option('once');
        $this->info('Xedik Dialer Worker started. Listening for running campaigns...');

        while (true) {
            try {
                $this->processQueue();
            } catch (\Throwable $e) {
                $this->error('Dialer worker error: ' . $e->getMessage());
                Log::error('Dialer worker exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }

            if ($once) {
                break;
            }

            sleep(2);
        }

        return 0;
    }

    private int $lastScheduleCheck = 0;

    private function processQueue(): void
    {
        // 0. Revisar campañas programadas y ventanas de horario cada 5 segundos
        if (time() - $this->lastScheduleCheck >= 5) {
            $this->lastScheduleCheck = time();
            try {
                $scheduler = app(\App\Services\Dialer\DialerSchedulerService::class);
                $scheduler->processScheduledCampaigns();
                $scheduler->checkDailyWindowsAndAutoPause();
            } catch (\Throwable $e) {
                Log::warning('Dialer scheduler check failed', ['error' => $e->getMessage()]);
            }
        }

        // 1. Get all running campaigns
        $campaigns = DB::table('dialer_campaigns')
            ->where('status', 'running')
            ->get();

        if ($campaigns->isEmpty()) {
            return;
        }

        foreach ($campaigns as $campaign) {
            $campaignId = (int) $campaign->id;

            // Get pending items for this campaign
            $pendingLeads = DB::table('queue_dial')
                ->where('dialer_campaign_id', $campaignId)
                ->where('status', 'pending')
                ->orderBy('id', 'asc')
                ->limit(3)
                ->get();

            if ($pendingLeads->isEmpty()) {
                // Check if any leads are still dialing
                $dialingCount = DB::table('queue_dial')
                    ->where('dialer_campaign_id', $campaignId)
                    ->where('status', 'dialing')
                    ->count();

                if ($dialingCount === 0) {
                    // Campaign finished
                    DB::table('dialer_campaigns')
                        ->where('id', $campaignId)
                        ->update([
                            'status'       => 'completed',
                            'completed_at' => now(),
                        ]);
                    $this->info("Campaign #{$campaignId} completed (no pending or dialing leads).");
                    Log::info("Campaign #{$campaignId} completed.");
                }
                continue;
            }

            foreach ($pendingLeads as $item) {
                $phone = preg_replace('/[^\d+]/', '', (string) $item->phone);
                if (strlen($phone) < 7) {
                    DB::table('queue_dial')->where('id', $item->id)->delete();
                    continue;
                }

                // Format phone for Asterisk outbound
                if (str_starts_with($phone, '+52') && strlen($phone) === 13) {
                    $dialExten = substr($phone, 3);
                } elseif (str_starts_with($phone, '52') && strlen($phone) === 12) {
                    $dialExten = substr($phone, 2);
                } else {
                    $dialExten = $phone;
                }

                // Mark dialing in queue_dial
                DB::table('queue_dial')
                    ->where('id', $item->id)
                    ->update(['status' => 'dialing']);

                $this->info("Dialing lead #{$item->dialer_lead_id} (Phone: {$dialExten}) for Campaign #{$campaignId}...");

                // Resolve voicebot agent according to campaign tenant
                $agentId = 'agent_0001m5h3zv32egsb2mfmgnd6a395'; // default Xedik Carlos
                if ($campaign && $campaign->tenant_id == 5) {
                    $agentId = 'agent_2101m5xst5cgf1gv86jxhf2an2nd'; // FOU Predator Eva
                }

                // Execute Asterisk originate
                // Originates Local/{dialExten}@xedik_outbound/n to connect to {agentId}@xedik_voicebot_outbound
                // Note: /n prevents local channel optimization so WebRTC DTLS-SRTP and audio translation remain intact
                $cmd = sprintf(
                    'asterisk -rx "channel originate Local/%s@xedik_outbound/n extension %s@xedik_voicebot_outbound"',
                    escapeshellcmd($dialExten),
                    escapeshellcmd($agentId)
                );

                $this->info("Originating call: {$cmd}");
                exec($cmd, $output, $returnCode);

                Log::info("Dialer originate executed", [
                    'lead_id'   => $item->dialer_lead_id,
                    'phone'     => $dialExten,
                    'agent_id'  => $agentId,
                    'cmd'       => $cmd,
                    'return'    => $returnCode,
                    'output'    => $output,
                ]);

                // Update lead in its lead table
                if (!empty($item->lead_list_table)) {
                    try {
                        DB::table($item->lead_list_table)
                            ->where('id', $item->dialer_lead_id)
                            ->update([
                                'called_count'   => DB::raw('called_count + 1'),
                                'last_called_at' => now(),
                                'status'         => 'DIALED',
                            ]);
                    } catch (\Throwable $e) {
                        Log::warning("Could not update {$item->lead_list_table}: " . $e->getMessage());
                    }
                }

                // Remove from queue_dial or mark done
                DB::table('queue_dial')->where('id', $item->id)->delete();

                // Rate limiting between calls in the same campaign
                sleep(1);
            }
        }
    }
}
