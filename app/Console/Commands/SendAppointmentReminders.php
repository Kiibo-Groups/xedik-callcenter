<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Call;
use App\Services\Appointments\AppointmentNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send automatic WhatsApp reminders for appointments scheduled for today';

    public function handle(AppointmentNotificationService $service): int
    {
        $today = Carbon::today();
        $this->info("Checking appointments scheduled for today ({$today->toDateString()})...");

        $pendingCalls = Call::withoutGlobalScopes()
            ->where('is_scheduled', true)
            ->where('completed', false)
            ->whereNull('wa_reminder_sent_at')
            ->whereDate('scheduled_at', $today)
            ->with(['lead'])
            ->get();

        $count = $pendingCalls->count();
        $this->info("Found {$count} pending appointment(s) needing reminder today.");

        $sentCount = 0;
        foreach ($pendingCalls as $call) {
            $this->line("Sending reminder for Call #{$call->id} (Lead #{$call->lead_id})...");
            $res = $service->sendReminder($call);
            if ($res['ok']) {
                $sentCount++;
                $this->info(" -> Reminder sent successfully! (WAMID: " . ($res['wamid'] ?? 'ok') . ")");
            } else {
                $this->warn(" -> Skipped or failed: " . ($res['error'] ?? 'Unknown error'));
            }
        }

        $this->info("Finished: {$sentCount}/{$count} reminders sent.");
        return 0;
    }
}
