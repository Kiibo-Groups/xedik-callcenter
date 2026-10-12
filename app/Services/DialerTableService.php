<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeadList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DialerTableService
{
    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    public function createLeadTable(string $tableName): void
    {
        $this->assertSafeDynamicTableName($tableName, 'lead');

        if ($this->isSqlite()) {
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$tableName}\" (
                \"id\"             INTEGER PRIMARY KEY AUTOINCREMENT,
                \"phone\"          TEXT NOT NULL,
                \"custom_fields\"  TEXT NULL,
                \"status\"         TEXT NOT NULL DEFAULT 'NEW',
                \"called_count\"   INTEGER NOT NULL DEFAULT 0,
                \"last_called_at\" DATETIME NULL,
                \"last_duration\"  INTEGER NULL,
                \"last_billsec\"   INTEGER NULL,
                \"callback_at\"    DATETIME NULL,
                \"reserved_by\"     INTEGER NULL,
                \"last_offered_to\" INTEGER NULL,
                \"last_offered_at\" DATETIME NULL,
                \"last_disposition_id\" INTEGER NULL,
                \"last_disposition\" TEXT NULL,
                \"last_disposition_at\" DATETIME NULL,
                \"last_disposition_by\" INTEGER NULL,
                \"created_at\"     DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_status\" ON \"{$tableName}\" (\"status\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_phone\" ON \"{$tableName}\" (\"phone\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_reserved_by\" ON \"{$tableName}\" (\"reserved_by\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_status_called\" ON \"{$tableName}\" (\"status\", \"called_count\")");

            return;
        }

        DB::statement("CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `phone`           VARCHAR(20) NOT NULL,
            `custom_fields`   JSON NULL,
            `status`          ENUM('NEW','ANSWER','BUSY','NO_ANSWER','FAILED','DNC','CALLBACK')
                              NOT NULL DEFAULT 'NEW',
            `called_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `last_called_at`  TIMESTAMP NULL,
            `last_duration`   INT UNSIGNED NULL,
            `last_billsec`    INT UNSIGNED NULL,
            `callback_at`     TIMESTAMP NULL,
            `reserved_by`     BIGINT UNSIGNED NULL,
            `last_offered_to` BIGINT UNSIGNED NULL,
            `last_offered_at` TIMESTAMP NULL,
            `last_disposition_id` BIGINT UNSIGNED NULL,
            `last_disposition` VARCHAR(100) NULL,
            `last_disposition_at` TIMESTAMP NULL,
            `last_disposition_by` BIGINT UNSIGNED NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (`status`),
            INDEX idx_phone (`phone`),
            INDEX idx_{$tableName}_reserved_by (`reserved_by`),
            INDEX idx_{$tableName}_status_called (`status`, `called_count`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function createLogTable(string $logTableName): void
    {
        $this->assertSafeDynamicTableName($logTableName, 'log');

        if ($this->isSqlite()) {
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$logTableName}\" (
                \"id\"                 INTEGER PRIMARY KEY AUTOINCREMENT,
                \"dialer_campaign_id\" INTEGER NOT NULL,
                \"dialer_lead_id\"     INTEGER NOT NULL,
                \"phone\"              TEXT NOT NULL,
                \"uniqueid\"           TEXT NULL,
                \"call_uuid\"          TEXT NULL,
                \"conversation_id\"    INTEGER NULL,
                \"final_status\"       TEXT NULL,
                \"disposition\"        TEXT NULL,
                \"duration\"           INTEGER DEFAULT 0,
                \"billsec\"            INTEGER DEFAULT 0,
                \"called_at\"          DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$logTableName}_campaign\" ON \"{$logTableName}\" (\"dialer_campaign_id\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$logTableName}_lead\" ON \"{$logTableName}\" (\"dialer_lead_id\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$logTableName}_called\" ON \"{$logTableName}\" (\"called_at\")");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS \"uq_{$logTableName}_call_uuid\" ON \"{$logTableName}\" (\"call_uuid\")");
            DB::statement("CREATE INDEX IF NOT EXISTS \"idx_{$logTableName}_conversation\" ON \"{$logTableName}\" (\"conversation_id\")");

            return;
        }

        DB::statement("CREATE TABLE IF NOT EXISTS `{$logTableName}` (
            `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `dialer_campaign_id` BIGINT UNSIGNED NOT NULL,
            `dialer_lead_id`     BIGINT UNSIGNED NOT NULL,
            `phone`              VARCHAR(20) NOT NULL,
            `uniqueid`           VARCHAR(50) NULL,
            `call_uuid`          VARCHAR(36) NULL,
            `conversation_id`    BIGINT UNSIGNED NULL,
            `final_status`       ENUM('ANSWER','NO_ANSWER','BUSY','CONGESTION','REJECTED','FAILED') NULL,
            `disposition`        VARCHAR(50) NULL,
            `duration`           INT UNSIGNED DEFAULT 0,
            `billsec`            INT UNSIGNED DEFAULT 0,
            `called_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_campaign (`dialer_campaign_id`),
            INDEX idx_lead (`dialer_lead_id`),
            INDEX idx_called (`called_at`),
            INDEX idx_final_status (`final_status`),
            UNIQUE INDEX uq_{$logTableName}_call_uuid (`call_uuid`),
            INDEX idx_{$logTableName}_conversation (`conversation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function dropLeadTable(string $tableName): void
    {
        $this->assertSafeDynamicTableName($tableName, 'lead');

        $q = $this->isSqlite() ? "\"{$tableName}\"" : "`{$tableName}`";
        DB::statement("DROP TABLE IF EXISTS {$q}");
    }

    public function dropLogTable(string $logTableName): void
    {
        $this->assertSafeDynamicTableName($logTableName, 'log');

        $q = $this->isSqlite() ? "\"{$logTableName}\"" : "`{$logTableName}`";
        DB::statement("DROP TABLE IF EXISTS {$q}");
    }

    public function countLeads(string $tableName): int
    {
        $this->assertSafeDynamicTableName($tableName, 'lead');

        return (int) DB::table($tableName)->count();
    }

    public function tableExists(string $tableName): bool
    {
        $this->assertSafeDynamicTableName($tableName);

        return DB::getSchemaBuilder()->hasTable($tableName);
    }

    public function isPreviewSchemaPrepared(LeadList $leadList): bool
    {
        return $this->previewSchemaStatus($leadList)['prepared'];
    }

    /**
     * @return array{
     *     prepared: bool,
     *     missing_tables: list<string>,
     *     missing_columns: array<string, list<string>>,
     *     missing_indexes: array<string, list<string>>
     * }
     */
    public function previewSchemaStatus(LeadList $leadList): array
    {
        $leadTable = (string) $leadList->table_name;
        $logTable = (string) $leadList->log_table_name;

        $this->assertSafeDynamicTableName($leadTable, 'lead');
        $this->assertSafeDynamicTableName($logTable, 'log');

        $missingTables = [];
        $missingColumns = [];
        $missingIndexes = [];

        if (!$this->tableExists($leadTable)) {
            $missingTables[] = $leadTable;
        } else {
            $missingColumns[$leadTable] = $this->missingColumns($leadTable, [
                'reserved_by',
                'last_offered_to',
                'last_offered_at',
                'last_disposition_id',
                'last_disposition',
                'last_disposition_at',
                'last_disposition_by',
            ]);

            $missingIndexes[$leadTable] = $this->missingIndexes($leadTable, [
                $this->leadReservedByIndexName($leadTable),
                $this->leadStatusCalledIndexName($leadTable),
            ]);
        }

        if (!$this->tableExists($logTable)) {
            $missingTables[] = $logTable;
        } else {
            $missingColumns[$logTable] = $this->missingColumns($logTable, [
                'call_uuid',
                'conversation_id',
            ]);

            $missingIndexes[$logTable] = $this->missingIndexes($logTable, [
                $this->logCallUuidIndexName($logTable),
                $this->logConversationIndexName($logTable),
            ]);
        }

        $hasMissingColumns = collect($missingColumns)->flatten()->isNotEmpty();
        $hasMissingIndexes = collect($missingIndexes)->flatten()->isNotEmpty();

        return [
            'prepared' => $missingTables === [] && !$hasMissingColumns && !$hasMissingIndexes,
            'missing_tables' => $missingTables,
            'missing_columns' => array_filter($missingColumns),
            'missing_indexes' => array_filter($missingIndexes),
        ];
    }

    /**
     * @return array{status: string, before: array, after: array}
     */
    public function preparePreviewSchema(LeadList $leadList): array
    {
        $before = $this->previewSchemaStatus($leadList);

        if ($before['missing_tables'] !== []) {
            return [
                'status' => 'skipped',
                'before' => $before,
                'after' => $before,
            ];
        }

        if ($before['prepared']) {
            return [
                'status' => 'already_prepared',
                'before' => $before,
                'after' => $before,
            ];
        }

        $leadTable = (string) $leadList->table_name;
        $logTable = (string) $leadList->log_table_name;

        $this->prepareLeadTableForPreview($leadTable);
        $this->prepareLogTableForPreview($logTable);

        $after = $this->previewSchemaStatus($leadList);

        return [
            'status' => $after['prepared'] ? 'prepared' : 'incomplete',
            'before' => $before,
            'after' => $after,
        ];
    }

    private function prepareLeadTableForPreview(string $tableName): void
    {
        $this->assertSafeDynamicTableName($tableName, 'lead');

        if (!Schema::hasColumn($tableName, 'reserved_by')) {
            Schema::table($tableName, fn ($table) => $table->unsignedBigInteger('reserved_by')->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_offered_to')) {
            Schema::table($tableName, fn ($table) => $table->unsignedBigInteger('last_offered_to')->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_offered_at')) {
            Schema::table($tableName, fn ($table) => $table->timestamp('last_offered_at')->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_disposition_id')) {
            Schema::table($tableName, fn ($table) => $table->unsignedBigInteger('last_disposition_id')->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_disposition')) {
            Schema::table($tableName, fn ($table) => $table->string('last_disposition', 100)->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_disposition_at')) {
            Schema::table($tableName, fn ($table) => $table->timestamp('last_disposition_at')->nullable());
        }

        if (!Schema::hasColumn($tableName, 'last_disposition_by')) {
            Schema::table($tableName, fn ($table) => $table->unsignedBigInteger('last_disposition_by')->nullable());
        }

        $this->createIndexIfMissing(
            $tableName,
            $this->leadReservedByIndexName($tableName),
            ['reserved_by']
        );

        $this->createIndexIfMissing(
            $tableName,
            $this->leadStatusCalledIndexName($tableName),
            ['status', 'called_count']
        );
    }

    private function prepareLogTableForPreview(string $tableName): void
    {
        $this->assertSafeDynamicTableName($tableName, 'log');

        if (!Schema::hasColumn($tableName, 'call_uuid')) {
            Schema::table($tableName, fn ($table) => $table->string('call_uuid', 36)->nullable());
        }

        if (!Schema::hasColumn($tableName, 'conversation_id')) {
            Schema::table($tableName, fn ($table) => $table->unsignedBigInteger('conversation_id')->nullable());
        }

        $this->createIndexIfMissing(
            $tableName,
            $this->logCallUuidIndexName($tableName),
            ['call_uuid'],
            unique: true
        );

        $this->createIndexIfMissing(
            $tableName,
            $this->logConversationIndexName($tableName),
            ['conversation_id']
        );
    }

    /**
     * @param list<string> $columns
     */
    private function createIndexIfMissing(string $tableName, string $indexName, array $columns, bool $unique = false): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function ($table) use ($columns, $indexName, $unique) {
            $unique
                ? $table->unique($columns, $indexName)
                : $table->index($columns, $indexName);
        });
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function missingColumns(string $tableName, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column): bool => !Schema::hasColumn($tableName, $column)
        ));
    }

    /**
     * @param list<string> $indexNames
     * @return list<string>
     */
    private function missingIndexes(string $tableName, array $indexNames): array
    {
        return array_values(array_filter(
            $indexNames,
            fn (string $indexName): bool => !$this->indexExists($tableName, $indexName)
        ));
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $this->assertSafeDynamicTableName($tableName);

        if ($this->isSqlite()) {
            $indexes = DB::select("PRAGMA index_list(\"{$tableName}\")");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function assertSafeDynamicTableName(string $tableName, ?string $type = null): void
    {
        $pattern = match ($type) {
            'lead' => '/^dl_[1-9][0-9]*_leads$/',
            'log' => '/^dl_[1-9][0-9]*_log$/',
            default => '/^dl_[1-9][0-9]*_(leads|log)$/',
        };

        if (!preg_match($pattern, $tableName)) {
            throw new \InvalidArgumentException("Unsafe dynamic dialer table name [{$tableName}].");
        }
    }

    private function leadReservedByIndexName(string $tableName): string
    {
        return "idx_{$tableName}_reserved_by";
    }

    private function leadStatusCalledIndexName(string $tableName): string
    {
        return "idx_{$tableName}_status_called";
    }

    private function logCallUuidIndexName(string $tableName): string
    {
        return "uq_{$tableName}_call_uuid";
    }

    private function logConversationIndexName(string $tableName): string
    {
        return "idx_{$tableName}_conversation";
    }
}
