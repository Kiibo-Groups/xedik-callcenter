<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LeadList;
use App\Services\DialerTableService;
use Illuminate\Console\Command;

class PreparePreviewDialerLeadLists extends Command
{
    protected $signature = 'preview-dialer:prepare-lead-lists {--lead-list-id= : Process only one Lead List ID}';

    protected $description = 'Prepare existing dynamic Lead List tables for Preview Dialer without touching data';

    public function __construct(private DialerTableService $tableService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $leadListId = $this->option('lead-list-id');

        if ($leadListId !== null && $leadListId !== '') {
            $leadList = LeadList::query()->find((int) $leadListId);

            if (!$leadList) {
                $this->error("[FAILED] Lead List {$leadListId}: no existe.");

                return self::FAILURE;
            }

            return $this->processLeadLists(collect([$leadList]));
        }

        return $this->processLeadLists(LeadList::query()->orderBy('id')->get());
    }

    private function processLeadLists(iterable $leadLists): int
    {
        $processed = 0;
        $prepared = 0;
        $alreadyPrepared = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($leadLists as $leadList) {
            $processed++;

            try {
                $result = $this->tableService->preparePreviewSchema($leadList);

                match ($result['status']) {
                    'prepared' => $prepared++,
                    'already_prepared' => $alreadyPrepared++,
                    'skipped' => $skipped++,
                    default => $failed++,
                };

                $this->writeLeadListResult($leadList, $result);
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("[FAILED] Lead List {$leadList->id}: {$exception->getMessage()}");
            }
        }

        $this->line('');
        $this->line("Procesadas: {$processed}");
        $this->line("Preparadas: {$prepared}");
        $this->line("Ya preparadas: {$alreadyPrepared}");
        $this->line("Omitidas: {$skipped}");
        $this->line("Fallidas: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeLeadListResult(LeadList $leadList, array $result): void
    {
        $id = $leadList->id;

        if ($result['status'] === 'already_prepared') {
            $this->info("[OK] Lead List {$id}: ya preparada");

            return;
        }

        if ($result['status'] === 'prepared') {
            $this->info("[DONE] Lead List {$id}: columnas e índices agregados");

            return;
        }

        if ($result['status'] === 'skipped') {
            $tables = implode(', ', $result['before']['missing_tables']);
            $this->warn("[SKIP] Lead List {$id}: tablas dinámicas no existen ({$tables})");

            return;
        }

        $this->error("[FAILED] Lead List {$id}: schema incompleto después de preparar");
    }
}
