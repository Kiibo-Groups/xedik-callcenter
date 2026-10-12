<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Datos del anuncio de origen (Click-to-WhatsApp): source_url, headline,
            // body, ctwa_clid, welcome_message + crm_file_id del preview descargado
            $table->json('referral_data')->nullable()->after('media_type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('referral_data');
        });
    }
};
