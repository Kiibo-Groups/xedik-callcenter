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
            if (!Schema::hasColumn('dialer_campaigns', 'compliance_config')) {
                $table->json('compliance_config')->nullable()->after('filter_config');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('dialer_campaigns', 'compliance_config')) {
                $table->dropColumn('compliance_config');
            }
        });
    }
};
