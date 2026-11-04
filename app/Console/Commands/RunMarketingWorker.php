<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Marketing\MarketingCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunMarketingWorker extends Command
{
    protected $signature = 'marketing:worker {--once : Run a single loop iteration and exit}';
    protected $description = 'Worker daemon to dispatch Email, SMS, and WhatsApp marketing campaigns';

    public function handle(MarketingCampaignService $service): int
    {
        $once = (bool) $this->option('once');
        $this->info('Xedik Marketing Worker started (Email / SMS / WhatsApp)...');

        while (true) {
            try {
                $service->processScheduledCampaigns();
                $service->checkDailyWindowsAndAutoPause();
                $service->processRunningCampaigns();
            } catch (\Throwable $e) {
                $this->error('Marketing worker error: ' . $e->getMessage());
                Log::error('Marketing worker exception', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if ($once) {
                break;
            }

            sleep(2);
        }

        return 0;
    }
}

