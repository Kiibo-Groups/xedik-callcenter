<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('dialer_campaigns', 'filter_config')) {
                $table->json('filter_config')->nullable()->after('lead_order_direction');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('dialer_campaigns', 'filter_config')) {
                $table->dropColumn('filter_config');
            }
        });
    }
};
