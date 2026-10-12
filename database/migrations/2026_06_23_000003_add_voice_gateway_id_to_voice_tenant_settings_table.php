<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_tenant_settings', function (Blueprint $table) {
            $table->foreignId('voice_gateway_id')
                ->nullable()
                ->after('default_caller_id')
                ->constrained('voice_gateways')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voice_tenant_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_gateway_id');
        });
    }
};
