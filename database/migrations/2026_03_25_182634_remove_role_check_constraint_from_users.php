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
            throw new \RuntimeException("Unsupported database driver [{$driver}] for users role check migration.");
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        try {
            DB::statement('CREATE TABLE users_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT "agent",
                is_active TINYINT NOT NULL DEFAULT 1,
                remember_token VARCHAR(100),
                created_at DATETIME,
                updated_at DATETIME,
                phone_user_id VARCHAR(50)
            )');

            DB::statement('INSERT INTO users_new SELECT * FROM users');
            DB::statement('DROP TABLE users');
            DB::statement('ALTER TABLE users_new RENAME TO users');
        } finally {
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }

    public function down(): void {}
};
