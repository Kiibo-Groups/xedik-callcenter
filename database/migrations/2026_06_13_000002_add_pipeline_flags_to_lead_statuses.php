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
        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->boolean('show_in_pipeline')->default(true)->after('is_active');
            $table->boolean('is_won')->default(false)->after('show_in_pipeline');
            $table->boolean('is_lost')->default(false)->after('is_won');
        });

        // show_in_pipeline ya queda en true para todos los status existentes
        // (default de la columna). Solo falta marcar won/lost por slug,
        // para todos los tenants.
        DB::table('lead_statuses')->where('slug', 'closed_won')->update(['is_won' => true]);
        DB::table('lead_statuses')->where('slug', 'closed_lost')->update(['is_lost' => true]);
    }

    public function down(): void
    {
        Schema::table('lead_statuses', function (Blueprint $table) {
            $table->dropColumn(['show_in_pipeline', 'is_won', 'is_lost']);
        });
    }
};
