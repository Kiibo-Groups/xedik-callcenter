<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Confirmed via sqlite_master / information_schema before writing this
    // migration — this is Laravel's default single-column unique index name
    // for voice_gateways.asterisk_endpoint_id (created by the original
    // 2026_06_23_000002_create_voice_gateways_table migration).
    private const INDEX_NAME = 'voice_gateways_asterisk_endpoint_id_unique';

    public function up(): void
    {
        // asterisk_endpoint_id is now a shared Asterisk trunk/endpoint reference,
        // not a unique physical identifier — multiple logical VoiceGateway rows
        // (differing by country_code / hidden_prefix, e.g. USA vs Mexico) may
        // intentionally route through the same PJSIP endpoint.
        Schema::table('voice_gateways', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        // Restoring this unique index will fail if duplicate asterisk_endpoint_id
        // values exist by that point (expected once multiple logical gateways
        // share a trunk) — only run this rollback after confirming no duplicates
        // remain in voice_gateways.
        Schema::table('voice_gateways', function (Blueprint $table) {
            $table->unique('asterisk_endpoint_id', self::INDEX_NAME);
        });
    }
};
