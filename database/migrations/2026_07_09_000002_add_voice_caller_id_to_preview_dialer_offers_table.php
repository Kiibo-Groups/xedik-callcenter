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
            $table->foreignId('voice_caller_id_id')
                ->nullable()
                ->after('dialer_campaign_id')
                ->constrained('voice_caller_ids')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropForeign(['voice_caller_id_id']);
            $table->dropColumn('voice_caller_id_id');
        });
    }
};
