<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'manage_dialer')
            ->update(['label' => 'Dispositions']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'manage_dialer')
            ->update(['label' => 'Dialer Whitelist']);
    }
};
