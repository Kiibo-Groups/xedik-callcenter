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
            // Frozen at accept() time from the resolved VoiceGateway.hidden_prefix —
            // sent to Asterisk as X-XEDIK-Dial-Prefix. Null when no gateway resolves.
            $table->string('dial_prefix', 20)->nullable()->after('voice_caller_id_id');
        });
    }

    public function down(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropColumn('dial_prefix');
        });
    }
};
