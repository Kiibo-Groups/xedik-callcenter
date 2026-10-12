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

        // MySQL: garantizar que users.tenant_id sea nullable
        if ($driver !== 'sqlite' && Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->change();
            });
        }

        // Superadmin no pertenece a ningún tenant
        DB::table('users')
            ->where('is_superadmin', true)
            ->update(['tenant_id' => null]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('is_superadmin', true)
            ->update(['tenant_id' => 1]);
    }
};
