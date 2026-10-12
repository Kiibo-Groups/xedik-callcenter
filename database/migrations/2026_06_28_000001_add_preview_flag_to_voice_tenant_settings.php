<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('voice_tenant_settings', 'preview_dialer_enabled')) {
            Schema::table('voice_tenant_settings', function (Blueprint $table) {
                $table->boolean('preview_dialer_enabled')
                    ->default(false)
                    ->after('voice_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('voice_tenant_settings', 'preview_dialer_enabled')) {
            Schema::table('voice_tenant_settings', function (Blueprint $table) {
                $table->dropColumn('preview_dialer_enabled');
            });
        }
    }
};
