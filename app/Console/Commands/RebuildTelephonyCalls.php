<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelephonyQueueLog;
use App\Services\Telephony\QueueLogImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RebuildTelephonyCalls extends Command
{
    protected $signature = 'telephony:rebuild-calls {--pbx-id= : Limit rebuild to one PBX id} {--linkedid= : Limit rebuild to one linkedid}';

    protected $description = 'Rebuild telephony_calls deterministically from stored telephony_queue_log events';

    public function handle(QueueLogImportService $importer): int
    {
        $pbxId = $this->option('pbx-id');
        $linkedid = $this->option('linkedid');

        $query = TelephonyQueueLog::withoutGlobalScopes()
            ->select('pbx_id', 'linkedid')
            ->whereNotNull('linkedid')
            ->where('linkedid', '<>', '')
            ->distinct()
            ->orderBy('pbx_id')
            ->orderBy('linkedid');

        if ($pbxId !== null && $pbxId !== '') {
            $query->where('pbx_id', (int) $pbxId);
        }

        if ($linkedid !== null && $linkedid !== '') {
            $query->where('linkedid', (string) $linkedid);
        }

        $processed = 0;
        $rebuilt = 0;
        $failed = 0;

        $query->chunk(100, function ($groups) use ($importer, &$processed, &$rebuilt, &$failed): void {
            foreach ($groups as $group) {
                $processed++;

                try {
                    if ($importer->rebuildCallFromStoredEvents((int) $group->pbx_id, (string) $group->linkedid)) {
                        $rebuilt++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('[telephony][queue-log] rebuild_failed', [
                        'pbx_id' => (int) $group->pbx_id,
                        'linkedid' => (string) $group->linkedid,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->error("pbx_id={$group->pbx_id} linkedid={$group->linkedid} error={$exception->getMessage()}");
                }
            }
        });

        $this->info("processed={$processed} rebuilt={$rebuilt} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
