<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTable();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->updateMysqlTable();

            return;
        }

        throw new \RuntimeException("Unsupported database driver [{$driver}] for dialer disposition color migration.");
    }

    public function down(): void {}

    private function colorMap(): array
    {
        return [
            'Test' => ['#92400e', '#fef3c7'],
            'Wrong Number' => ['#ffffff', '#ef4444'],
            'Voice Mail' => ['#1e40af', '#dbeafe'],
            'Answering Machine' => ['#1e40af', '#dbeafe'],
            'Support' => ['#065f46', '#d1fae5'],
            'Sale' => ['#ffffff', '#16a34a'],
            'Customer' => ['#ffffff', '#16a34a'],
            'Do not Call' => ['#ffffff', '#dc2626'],
            'Not Available' => ['#374151', '#f3f4f6'],
            'Task' => ['#92400e', '#fef9c3'],
            'Callback' => ['#92400e', '#fef9c3'],
            'Invalid' => ['#ffffff', '#ef4444'],
            'Appt Set' => ['#4c1d95', '#ede9fe'],
            'No answer' => ['#374151', '#f3f4f6'],
            'Fax' => ['#374151', '#f3f4f6'],
            'Not Interested' => ['#ffffff', '#dc2626'],
        ];
    }

    private function rebuildSqliteTable(): void
    {
        $this->disableForeignKeys();

        try {
            DB::statement('CREATE TABLE dialer_dispositions_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR NOT NULL,
                color VARCHAR NOT NULL DEFAULT "#ffffff",
                bg_color VARCHAR NOT NULL DEFAULT "#64748b",
                is_active BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME
            )');

            $existing = DB::table('dialer_dispositions')->get();
            foreach ($existing as $row) {
                $colors = $this->colorMap()[$row->name] ?? ['#374151', '#f3f4f6'];
                DB::statement(
                    'INSERT INTO dialer_dispositions_new (id, name, color, bg_color, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$row->id, $row->name, $colors[0], $colors[1], $row->is_active, $row->created_at, $row->updated_at]
                );
            }

            DB::statement('DROP TABLE dialer_dispositions');
            DB::statement('ALTER TABLE dialer_dispositions_new RENAME TO dialer_dispositions');
        } finally {
            $this->enableForeignKeys();
        }
    }

    private function updateMysqlTable(): void
    {
        $this->disableForeignKeys();

        try {
            Schema::table('dialer_dispositions', function (Blueprint $table) {
                if (!Schema::hasColumn('dialer_dispositions', 'color')) {
                    $table->string('color')->default('#ffffff')->after('name');
                }

                if (!Schema::hasColumn('dialer_dispositions', 'bg_color')) {
                    $table->string('bg_color')->default('#64748b')->after('color');
                }
            });

            foreach ($this->colorMap() as $name => $colors) {
                DB::table('dialer_dispositions')
                    ->where('name', $name)
                    ->update([
                        'color' => $colors[0],
                        'bg_color' => $colors[1],
                    ]);
            }

            if (Schema::hasColumn('dialer_dispositions', 'crm_disposition')) {
                Schema::table('dialer_dispositions', function (Blueprint $table) {
                    $table->dropColumn('crm_disposition');
                });
            }
        } finally {
            $this->enableForeignKeys();
        }
    }

    private function disableForeignKeys(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => DB::statement('PRAGMA foreign_keys=OFF'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            default => null,
        };
    }

    private function enableForeignKeys(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => DB::statement('PRAGMA foreign_keys=ON'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            default => null,
        };
    }
};
