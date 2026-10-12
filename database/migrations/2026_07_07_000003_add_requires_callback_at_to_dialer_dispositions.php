<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('dialer_dispositions', 'requires_callback_at')) {
            Schema::table('dialer_dispositions', function (Blueprint $table) {
                $table->boolean('requires_callback_at')->default(false)->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dialer_dispositions', 'requires_callback_at')) {
            Schema::table('dialer_dispositions', function (Blueprint $table) {
                $table->dropColumn('requires_callback_at');
            });
        }
    }
};
