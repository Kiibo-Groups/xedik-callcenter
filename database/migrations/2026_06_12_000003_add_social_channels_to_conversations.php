<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extiende el enum channel con facebook/instagram.
     * MySQL: ALTER MODIFY COLUMN (mismo patrón que la migración de whatsapp).
     * SQLite: no valida enums (los trata como TEXT) — no requiere cambio.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat','whatsapp','facebook','instagram') NOT NULL DEFAULT 'email'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat','whatsapp') NOT NULL DEFAULT 'email'");
        }
    }
};
