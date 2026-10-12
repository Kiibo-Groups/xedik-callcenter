<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas genéricas provider_* — no específicas de DIDWW — para que cualquier
     * proveedor SMS futuro (Twilio, Telnyx, SMS Masivos MX) las use sin nueva migración.
     * provider_message_id sirve doble propósito: correlación de status callback
     * (outbound) y dedup de reintentos (inbound, cuando el proveedor no entrega un id
     * nativo — se guarda un hash sintético).
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('message_id');
            $table->string('provider_status')->nullable()->after('provider_message_id');
            $table->string('provider_error_code')->nullable()->after('provider_status');
            $table->unsignedInteger('segments')->nullable()->after('provider_error_code');
            $table->decimal('provider_cost', 8, 4)->nullable()->after('segments');
            $table->timestamp('provider_started_at')->nullable()->after('provider_cost');
            $table->timestamp('provider_completed_at')->nullable()->after('provider_started_at');
            $table->json('provider_payload')->nullable()->after('provider_completed_at');

            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn([
                'provider_message_id', 'provider_status', 'provider_error_code',
                'segments', 'provider_cost', 'provider_started_at', 'provider_completed_at',
                'provider_payload',
            ]);
        });
    }
};
