<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE omni_queues MODIFY type ENUM('email','chat','voice','omni') NOT NULL DEFAULT 'email'");
        }
    }

    public function down(): void
    {
        if (DB::table('omni_queues')->where('type', 'voice')->exists()) {
            throw new RuntimeException(
                "Cannot rollback omni_queues.type while voice queues exist. Convert or remove voice queues first."
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE omni_queues MODIFY type ENUM('email','chat','omni') NOT NULL DEFAULT 'email'");
        }
    }
};
