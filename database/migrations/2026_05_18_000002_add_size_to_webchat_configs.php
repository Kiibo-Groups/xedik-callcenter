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
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            if (!Schema::hasColumn('webchat_configs', 'size')) {
                DB::statement("ALTER TABLE webchat_configs ADD COLUMN size VARCHAR(10) DEFAULT 'medium'");
            }
        } else {
            Schema::table('webchat_configs', function (Blueprint $table) {
                if (!Schema::hasColumn('webchat_configs', 'size')) {
                    $table->string('size', 10)->default('medium');
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;
        Schema::table('webchat_configs', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
