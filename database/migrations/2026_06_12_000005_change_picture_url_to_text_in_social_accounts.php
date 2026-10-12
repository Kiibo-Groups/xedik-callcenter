<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las URLs del CDN de Facebook (firmadas) superan los 600 chars — varchar(500)
     * truncaba en MariaDB (SQLite no valida longitudes, por eso local no falló).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE social_accounts MODIFY COLUMN picture_url TEXT NULL');
        }
        // SQLite: los varchar son TEXT sin límite real — no requiere cambio
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE social_accounts MODIFY COLUMN picture_url VARCHAR(500) NULL');
        }
    }
};
