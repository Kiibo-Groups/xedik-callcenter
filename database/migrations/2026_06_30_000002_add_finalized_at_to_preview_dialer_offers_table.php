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
            $table->timestamp('finalized_at')->nullable()->after('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropColumn('finalized_at');
        });
    }
};
