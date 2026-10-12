<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DialerCampaign;
use App\Models\LeadList;
use App\Models\PreviewDialerOffer;
use App\Models\User;
use App\Services\DialerTableService;
use App\Services\PreviewDialerOfferService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class QaPreviewDialerConcurrency extends Command
{
    protected $signature = 'qa:preview-dialer-concurrency
        {--inspect-only : Only validate the sandbox connection and list current tables}
        {--iterations=10 : Number of full concurrency scenarios to run}
        {--worker-agent-id= : Internal worker mode for one agent claim}
        {--worker-action=claim : Internal worker action: claim or current_then_claim}';

    protected $description = 'Guarded QA-only Preview Dialer MySQL/MariaDB concurrency smoke test';

    private const ACTIVE_STATUSES = ['pending', 'accepted'];

    public function handle(
        DialerTableService $tableService,
        PreviewDialerOfferService $offerService
    ): int {
        $safety = $this->assertSandboxSafety();

        if (!$safety['ok']) {
            return self::FAILURE;
        }

        if ($this->option('inspect-only')) {
            $this->writeSandboxInspection($safety);

            return self::SUCCESS;
        }

        $workerAgentId = $this->option('worker-agent-id');
        if ($workerAgentId !== null && $workerAgentId !== '') {
            return $this->runWorker($offerService, (int) $workerAgentId, (string) $this->option('worker-action'));
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $this->writeSandboxInspection($safety);

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $this->line("ITERATION {$iteration}");
            $ctx = $this->setupData($tableService, $iteration);

            $roundOne = $this->runConcurrentClaims($ctx['agents']);
            if (!$this->validateRound($ctx, 'claim_round', $roundOne, 5)) {
                return self::FAILURE;
            }

            $roundTwo = $this->runConcurrentClaims($ctx['agents'], 'current_then_claim');
            if (!$this->validateRound($ctx, 'repeated_polling', $roundTwo, 5, expectCreated: false)) {
                return self::FAILURE;
            }

            $activeOffers = $this->activeOffers($ctx);
            $releasedOffers = $activeOffers->take(2);
            foreach ($releasedOffers as $offer) {
                $agent = $ctx['agents']->firstWhere('id', (int) $offer->user_id);
                $offerService->decline($agent, (string) $offer->reservation_token);
            }

            $foreignReservedLead = collect($ctx['lead_ids'])
                ->diff($this->activeOffers($ctx)->pluck('lead_list_row_id')->map(fn ($id) => (int) $id)->all())
                ->first();
            DB::table($ctx['lead_table'])
                ->where('id', $foreignReservedLead)
                ->update(['reserved_by' => $ctx['agents']->last()->id]);

            $releasedAgents = $releasedOffers
                ->map(fn ($offer) => $ctx['agents']->firstWhere('id', (int) $offer->user_id))
                ->values();

            $roundThree = $this->runConcurrentClaims($releasedAgents);
            if (!$this->validateRound($ctx, 'release_reoffer', $roundThree, 5)) {
                return self::FAILURE;
            }

            $replacementLeadIds = collect($roundThree)
                ->pluck('lead_list_row_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            if (in_array((int) $foreignReservedLead, $replacementLeadIds, true)) {
                $this->error("Foreign reserved lead {$foreignReservedLead} was claimed by another agent.");

                return self::FAILURE;
            }

            $uniqueOffered = PreviewDialerOffer::withoutGlobalScopes()
                ->where('tenant_id', $ctx['tenant_id'])
                ->where('dialer_campaign_id', $ctx['dialer_campaign_id'])
                ->distinct('lead_list_row_id')
                ->count('lead_list_row_id');

            if ($uniqueOffered < 7) {
                $this->error("Expected unique offered leads to increase after release/reoffer; observed {$uniqueOffered}.");

                return self::FAILURE;
            }

            $this->info("ITERATION {$iteration} PASS");
        }

        $this->info('QA preview dialer concurrency smoke test passed.');

        return self::SUCCESS;
    }

    private function assertSandboxSafety(): array
    {
        $env = app()->environment();
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $driver = (string) config("database.connections.{$connection}.driver");
        $databaseLooksSafe = preg_match('/(sandbox|test|testing|preview)/i', $database) === 1;
        $driverLooksSafe = in_array($driver, ['mysql', 'mariadb'], true);

        $ok = $env === 'testing' && $driverLooksSafe && $databaseLooksSafe;

        if (!$ok) {
            $this->error('Sandbox safety check failed.');
            $this->line("APP_ENV={$env}");
            $this->line("DB_CONNECTION={$connection}");
            $this->line("DB_DRIVER={$driver}");
            $this->line("DB_DATABASE={$database}");
            $this->line('Required: APP_ENV=testing, mysql/mariadb driver, and DB name containing sandbox/test/testing/preview.');

            return compact('ok', 'env', 'connection', 'driver', 'database') + [
                'tables' => [],
                'grants' => [],
                'current_user' => null,
            ];
        }

        try {
            DB::connection()->getPdo();
            $currentDatabase = DB::selectOne('select database() as db')->db ?? $database;
            $currentUser = DB::selectOne('select current_user() as u')->u ?? null;
            $tables = array_map(
                fn ($row) => array_values((array) $row)[0],
                DB::select('show tables')
            );
            $grants = array_map(
                fn ($row) => implode(' ', array_values((array) $row)),
                DB::select('show grants for current_user')
            );
        } catch (Throwable $exception) {
            $this->error('Sandbox connection check failed: ' . $exception->getMessage());

            return compact('env', 'connection', 'driver', 'database') + [
                'ok' => false,
                'tables' => [],
                'grants' => [],
                'current_user' => null,
            ];
        }

        $grantsLookScoped = collect($grants)->every(function (string $grant) use ($currentDatabase): bool {
            $normalized = strtolower($grant);

            if (Str::contains($normalized, 'grant usage on *.*')) {
                return true;
            }

            return !Str::contains($normalized, [' on *.* '])
                && (
                    !Str::contains($normalized, ' on ')
                    || Str::contains($grant, "`{$currentDatabase}`.*")
                    || Str::contains($grant, $currentDatabase . '.*')
                );
        });

        if (!$grantsLookScoped) {
            $this->error('Sandbox safety check failed: DB grants do not appear scoped to the sandbox database.');
            foreach ($grants as $grant) {
                $this->line($this->redactGrant($grant));
            }

            return compact('env', 'connection', 'driver', 'database') + [
                'ok' => false,
                'tables' => $tables,
                'grants' => $grants,
                'current_user' => $currentUser,
            ];
        }

        return compact('env', 'connection', 'driver', 'database') + [
            'ok' => true,
            'tables' => $tables,
            'grants' => $grants,
            'current_user' => $currentUser,
        ];
    }

    private function redactGrant(string $grant): string
    {
        return preg_replace("/IDENTIFIED BY PASSWORD '\\*[^']+'/i", "IDENTIFIED BY PASSWORD '********'", $grant) ?? $grant;
    }

    private function writeSandboxInspection(array $safety): void
    {
        $this->line("APP_ENV={$safety['env']}");
        $this->line("DB_CONNECTION={$safety['connection']}");
        $this->line("DB_DRIVER={$safety['driver']}");
        $this->line("DB_DATABASE={$safety['database']}");
        $this->line("DB_CURRENT_USER={$safety['current_user']}");
        $this->line('TABLE_COUNT=' . count($safety['tables']));

        foreach ($safety['tables'] as $table) {
            $this->line("TABLE={$table}");
        }
    }

    private function runWorker(PreviewDialerOfferService $offerService, int $agentId, string $action): int
    {
        $agent = User::withoutGlobalScopes()->findOrFail($agentId);

        try {
            $current = $action === 'current_then_claim' ? $offerService->currentOffer($agent) : null;
            $result = $offerService->claim($agent);
            $offer = $result['offer'];

            $this->line(json_encode([
                'ok' => true,
                'agent_id' => $agentId,
                'created' => $result['created'],
                'reason' => $result['reason'],
                'current_offer_id' => $current?->id,
                'offer_id' => $offer?->id,
                'lead_list_row_id' => $offer?->lead_list_row_id,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line(json_encode([
                'ok' => false,
                'agent_id' => $agentId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function setupData(DialerTableService $tableService, int $iteration): array
    {
        $suffix = 'qa-preview-concurrency-' . $iteration . '-' . Str::lower(Str::random(8));
        $tenantId = (int) DB::table('tenants')->insertGetId([
            'name' => 'Tenant ' . $suffix,
            'slug' => $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = (int) DB::table('roles')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'agent',
            'label' => 'Agent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agents = collect();
        for ($i = 1; $i <= 5; $i++) {
            $agent = User::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'name' => "Agent {$i} {$suffix}",
                'email' => "agent{$i}-{$suffix}@qa-preview.test",
                'password' => bcrypt('password'),
                'role_id' => $roleId,
                'is_active' => true,
            ]);

            DB::table('agent_sessions')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $agent->id,
                'status' => 'ready',
                'status_since' => now(),
                'last_ping_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $agents->push($agent);
        }

        $campaignId = (int) DB::table('campaigns')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'CRM Campaign ' . $suffix,
            'slug' => 'crm-' . $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($agents as $agent) {
            DB::table('campaign_user')->insert([
                'campaign_id' => $campaignId,
                'user_id' => $agent->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $leadListId = (int) DB::table('lead_lists')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Lead List ' . $suffix,
            'table_name' => 'placeholder-' . $suffix,
            'log_table_name' => 'placeholder-log-' . $suffix,
            'total_leads' => 50,
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leadTable = "dl_{$leadListId}_leads";
        $logTable = "dl_{$leadListId}_log";
        DB::table('lead_lists')->where('id', $leadListId)->update([
            'table_name' => $leadTable,
            'log_table_name' => $logTable,
        ]);
        $tableService->createLeadTable($leadTable);
        $tableService->createLogTable($logTable);
        $tableService->preparePreviewSchema(LeadList::withoutGlobalScopes()->findOrFail($leadListId));

        $scriptId = (int) DB::table('scripts')->insertGetId([
            'tenant_id' => $tenantId,
            'campaign_id' => $campaignId,
            'name' => 'Preview Script ' . $suffix,
            'type' => 'builder',
            'body' => '<p>Hello</p>',
            'url' => null,
            'url_target' => 'panel',
            'url_params' => null,
            'is_active' => true,
            'direction' => 'both',
            'is_default' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('voice_tenant_settings')->insert([
            'tenant_id' => $tenantId,
            'voice_enabled' => true,
            'preview_dialer_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dialerCampaign = DialerCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => 'Preview ' . $suffix,
            'campaign_id' => $campaignId,
            'lead_list_id' => $leadListId,
            'script_id' => $scriptId,
            'dialer_type' => 'preview',
            'caller_id' => 'preview',
            'context' => 'preview',
            'max_retries' => 3,
            'dial_block_size' => 1,
            'offer_timeout_seconds' => 60,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $leadIds = [];
        for ($i = 1; $i <= 50; $i++) {
            $leadIds[] = (int) DB::table($leadTable)->insertGetId([
                'phone' => '555' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'custom_fields' => json_encode(['name' => "Lead {$i}"], JSON_THROW_ON_ERROR),
                'status' => 'NEW',
                'called_count' => 0,
                'reserved_by' => null,
                'last_offered_to' => null,
                'last_offered_at' => null,
                'created_at' => now(),
            ]);
        }

        return [
            'tenant_id' => $tenantId,
            'agents' => $agents,
            'dialer_campaign_id' => (int) $dialerCampaign->id,
            'lead_list_id' => $leadListId,
            'lead_table' => $leadTable,
            'lead_ids' => $leadIds,
        ];
    }

    private function runConcurrentClaims(Collection $agents, string $action = 'claim'): array
    {
        $processes = [];
        foreach ($agents as $agent) {
            $process = new Process([
                PHP_BINARY,
                'artisan',
                'qa:preview-dialer-concurrency',
                '--env=testing',
                '--worker-agent-id=' . $agent->id,
                '--worker-action=' . $action,
            ], base_path(), null, null, 60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $lines = preg_split('/\R/', trim($process->getOutput()));
            $json = $lines ? end($lines) : '';
            $decoded = json_decode((string) $json, true);
            if (!is_array($decoded)) {
                $decoded = [
                    'ok' => false,
                    'exception' => 'InvalidWorkerOutput',
                    'message' => trim($process->getErrorOutput() . "\n" . $process->getOutput()),
                ];
            }
            $results[] = $decoded;
        }

        return $results;
    }

    private function validateRound(array $ctx, string $label, array $workerResults, int $expectedActive, bool $expectCreated = true): bool
    {
        foreach ($workerResults as $result) {
            if (($result['ok'] ?? false) !== true) {
                $this->error("{$label}: worker failed: " . json_encode($result));

                return false;
            }
            if ($expectCreated && ($result['created'] ?? false) !== true) {
                $this->error("{$label}: expected created offer: " . json_encode($result));

                return false;
            }
        }

        $offers = $this->activeOffers($ctx);
        if ($offers->count() !== $expectedActive) {
            $this->error("{$label}: expected {$expectedActive} active offers, observed {$offers->count()}.");

            return false;
        }

        if ($offers->pluck('lead_list_row_id')->unique()->count() !== $offers->count()) {
            $this->error("{$label}: duplicate active lead row detected.");

            return false;
        }

        if ($offers->pluck('user_id')->unique()->count() !== $offers->count()) {
            $this->error("{$label}: duplicate active agent slot detected.");

            return false;
        }

        foreach ($offers as $offer) {
            if ((int) $offer->tenant_id !== $ctx['tenant_id']
                || (int) $offer->dialer_campaign_id !== $ctx['dialer_campaign_id']
                || (int) $offer->lead_list_id !== $ctx['lead_list_id']
                || (int) $offer->active_slot !== 1) {
                $this->error("{$label}: active offer ownership/slot mismatch on offer {$offer->id}.");

                return false;
            }

            $lead = DB::table($ctx['lead_table'])->where('id', (int) $offer->lead_list_row_id)->first();
            if (!$lead || (int) $lead->reserved_by !== (int) $offer->user_id || (int) $lead->last_offered_to !== (int) $offer->user_id) {
                $this->error("{$label}: lead reservation mismatch on offer {$offer->id}.");

                return false;
            }
        }

        $duplicateLeadRows = PreviewDialerOffer::withoutGlobalScopes()
            ->where('lead_list_id', $ctx['lead_list_id'])
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->groupBy('lead_list_id', 'lead_list_row_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $duplicateAgentSlots = PreviewDialerOffer::withoutGlobalScopes()
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->groupBy('tenant_id', 'user_id', 'active_slot')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateLeadRows || $duplicateAgentSlots) {
            $this->error("{$label}: database duplicate active constraint violation detected.");

            return false;
        }

        $this->info("{$label}: PASS");

        return true;
    }

    private function activeOffers(array $ctx): Collection
    {
        return PreviewDialerOffer::withoutGlobalScopes()
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('dialer_campaign_id', $ctx['dialer_campaign_id'])
            ->where('lead_list_id', $ctx['lead_list_id'])
            ->where('active_slot', 1)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('user_id')
            ->get();
    }
}
