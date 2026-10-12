<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->dynamicLeadTables() as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'last_disposition_id')) {
                    $table->unsignedBigInteger('last_disposition_id')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'last_disposition')) {
                    $table->string('last_disposition', 100)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'last_disposition_at')) {
                    $table->timestamp('last_disposition_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'last_disposition_by')) {
                    $table->unsignedBigInteger('last_disposition_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->dynamicLeadTables() as $tableName) {
            $columns = array_values(array_filter([
                Schema::hasColumn($tableName, 'last_disposition_id') ? 'last_disposition_id' : null,
                Schema::hasColumn($tableName, 'last_disposition') ? 'last_disposition' : null,
                Schema::hasColumn($tableName, 'last_disposition_at') ? 'last_disposition_at' : null,
                Schema::hasColumn($tableName, 'last_disposition_by') ? 'last_disposition_by' : null,
            ]));

            if ($columns !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function dynamicLeadTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'dl\\_%\\_leads' ESCAPE '\\'"
            ))
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && preg_match('/^dl_[1-9][0-9]*_leads$/', $name))
                ->values()
                ->all();
        }

        return collect(DB::select(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'dl\\_%\\_leads'"
        ))
            ->map(fn ($row) => $row->TABLE_NAME ?? $row->table_name ?? null)
            ->filter(fn ($name) => is_string($name) && preg_match('/^dl_[1-9][0-9]*_leads$/', $name))
            ->values()
            ->all();
    }
};
