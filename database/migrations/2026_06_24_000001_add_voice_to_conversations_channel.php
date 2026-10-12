<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support ALTER COLUMN for ENUM; string columns accept any value
        // and validation is enforced at application level. For MySQL/MariaDB, extend the enum.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE conversations MODIFY COLUMN channel
                 ENUM('email','chat','whatsapp','facebook','instagram','voice')
                 NOT NULL DEFAULT 'email'"
            );
        }
        // SQLite: column already VARCHAR-compatible; no DDL change needed.
        // Application-level validation (Conversation::CHANNELS constant) enforces allowed values.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE conversations MODIFY COLUMN channel
                 ENUM('email','chat','whatsapp','facebook','instagram')
                 NOT NULL DEFAULT 'email'"
            );
        }
    }
};
