<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('preview_dialer_offers', 'declined_at')) {
                $table->timestamp('declined_at')->nullable()->after('accepted_at');
            }

            if (!Schema::hasColumn('preview_dialer_offers', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('declined_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            if (Schema::hasColumn('preview_dialer_offers', 'expired_at')) {
                $table->dropColumn('expired_at');
            }

            if (Schema::hasColumn('preview_dialer_offers', 'declined_at')) {
                $table->dropColumn('declined_at');
            }
        });
    }
};
