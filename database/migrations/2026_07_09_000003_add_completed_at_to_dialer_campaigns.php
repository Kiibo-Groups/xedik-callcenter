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
            if (!Schema::hasColumn('dialer_campaigns', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('dialer_campaigns', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
