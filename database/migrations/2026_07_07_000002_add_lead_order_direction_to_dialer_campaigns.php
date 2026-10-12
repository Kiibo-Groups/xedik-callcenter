<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            if (!Schema::hasColumn('dialer_campaigns', 'lead_order_direction')) {
                $table->string('lead_order_direction', 10)
                    ->default('asc')
                    ->after('lead_list_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            if (Schema::hasColumn('dialer_campaigns', 'lead_order_direction')) {
                $table->dropColumn('lead_order_direction');
            }
        });
    }
};
