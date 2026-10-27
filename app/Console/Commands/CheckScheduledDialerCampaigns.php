<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dialer\DialerSchedulerService;
use Illuminate\Console\Command;

class CheckScheduledDialerCampaigns extends Command
{
    protected $signature = 'dialer:check-scheduled';
    protected $description = 'Launch scheduled dialer campaigns and enforce daily calling windows';

    public function handle(DialerSchedulerService $scheduler): int
    {
        $started = $scheduler->processScheduledCampaigns();
        $window = $scheduler->checkDailyWindowsAndAutoPause();

        $this->info("Scheduled campaigns checked: {$started} launched, {$window['paused']} paused, {$window['resumed']} resumed.");
        return 0;
    }
}
