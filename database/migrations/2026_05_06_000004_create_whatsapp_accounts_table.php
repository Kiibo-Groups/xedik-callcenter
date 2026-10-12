<?php
// database/migrations/2026_05_06_000004_create_whatsapp_accounts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('omni_queue_id')->nullable()->constrained('omni_queues')->nullOnDelete();
            $table->string('display_name');
            $table->string('phone_number');        // +52... (display)
            $table->string('phone_number_id')->unique(); // de Meta — el router del webhook
            $table->string('waba_id')->nullable(); // WhatsApp Business Account ID
            $table->text('access_token');          // Cast encrypted en modelo
            $table->string('verify_token');        // El que pones en Meta dashboard
            $table->string('app_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // Ahora sí podemos agregar el FK de conversations → whatsapp_accounts
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('whatsapp_account_id')
                  ->references('id')
                  ->on('whatsapp_accounts')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_account_id']);
        });

        Schema::dropIfExists('whatsapp_accounts');
    }
};