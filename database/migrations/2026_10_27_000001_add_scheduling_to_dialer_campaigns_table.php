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
        // 1. Modificar el enum de status para permitir 'scheduled'
        DB::statement("ALTER TABLE dialer_campaigns MODIFY COLUMN status ENUM('idle','running','paused','completed','scheduled') NOT NULL DEFAULT 'idle'");

        // 2. Agregar columnas de programación por fecha y horario
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_at');
            $table->time('daily_start_time')->nullable()->after('scheduled_end_at');
            $table->time('daily_end_time')->nullable()->after('daily_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_at', 'scheduled_end_at', 'daily_start_time', 'daily_end_time']);
        });

        DB::statement("ALTER TABLE dialer_campaigns MODIFY COLUMN status ENUM('idle','running','paused','completed') NOT NULL DEFAULT 'idle'");
    }
};
