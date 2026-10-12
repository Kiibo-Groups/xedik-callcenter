<?php
// database/migrations/2026_05_06_000003_update_conversations_for_whatsapp.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cambiar enum channel para incluir whatsapp
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat','whatsapp') NOT NULL DEFAULT 'email'");
        }

        Schema::table('conversations', function (Blueprint $table) {
            // 2. FK a whatsapp_accounts (la creamos en migración siguiente)
            $table->unsignedBigInteger('whatsapp_account_id')->nullable()->after('email_account_id');

            // 3. ID externo: teléfono del contacto en WhatsApp, thread-id en email, etc.
            $table->string('external_id')->nullable()->after('subject');

            // Índice compuesto para buscar conversación por canal + id externo
            $table->index(['tenant_id', 'channel', 'external_id'], 'idx_conv_tenant_channel_external');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conv_tenant_channel_external');
            $table->dropColumn(['whatsapp_account_id', 'external_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE conversations MODIFY COLUMN channel ENUM('email','chat') NOT NULL DEFAULT 'email'");
        }
    }
};
