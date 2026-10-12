<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if ($driver !== 'sqlite') {
            throw new \RuntimeException("Unsupported database driver [{$driver}] for calls disposition check migration.");
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        try {
            DB::statement('CREATE TABLE calls_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lead_id INTEGER NOT NULL,
                agent_id INTEGER NULL,
                disposition VARCHAR NOT NULL,
                called_at DATETIME NOT NULL,
                scheduled_at DATETIME NULL,
                duration INTEGER DEFAULT 0,
                notes TEXT NULL,
                recording_id VARCHAR NULL,
                is_scheduled BOOLEAN DEFAULT 0,
                completed BOOLEAN DEFAULT 1,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
                FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL
            )');

            DB::statement('INSERT INTO calls_new (
                id,
                lead_id,
                agent_id,
                disposition,
                called_at,
                scheduled_at,
                duration,
                notes,
                recording_id,
                is_scheduled,
                completed,
                created_at,
                updated_at
            )
            SELECT
                id,
                lead_id,
                agent_id,
                disposition,
                called_at,
                scheduled_at,
                duration,
                notes,
                recording_id,
                is_scheduled,
                completed,
                created_at,
                updated_at
            FROM calls');
            DB::statement('DROP TABLE calls');
            DB::statement('ALTER TABLE calls_new RENAME TO calls');
        } finally {
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }

    public function down(): void {}
};
