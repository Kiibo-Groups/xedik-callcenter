<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar campos a tabla calls
        Schema::table('calls', function (Blueprint $table) {
            if (!Schema::hasColumn('calls', 'appointment_type')) {
                $table->string('appointment_type', 50)->nullable()->after('is_scheduled')->comment('visit, demo, callback, call');
            }
            if (!Schema::hasColumn('calls', 'wa_confirmation_sent_at')) {
                $table->timestamp('wa_confirmation_sent_at')->nullable()->after('completed');
            }
            if (!Schema::hasColumn('calls', 'wa_reminder_sent_at')) {
                $table->timestamp('wa_reminder_sent_at')->nullable()->after('wa_confirmation_sent_at');
            }
        });

        // 2. Agregar configuración a dialer_campaigns
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('dialer_campaigns', 'appointment_wa_confirmation')) {
                $table->boolean('appointment_wa_confirmation')->default(true)->after('voicebot_agent_id');
            }
            if (!Schema::hasColumn('dialer_campaigns', 'appointment_wa_reminder')) {
                $table->boolean('appointment_wa_reminder')->default(true)->after('appointment_wa_confirmation');
            }
            if (!Schema::hasColumn('dialer_campaigns', 'confirmation_wa_template_id')) {
                $table->foreignId('confirmation_wa_template_id')->nullable()->after('appointment_wa_reminder')
                    ->constrained('whatsapp_templates')->nullOnDelete();
            }
            if (!Schema::hasColumn('dialer_campaigns', 'reminder_wa_template_id')) {
                $table->foreignId('reminder_wa_template_id')->nullable()->after('confirmation_wa_template_id')
                    ->constrained('whatsapp_templates')->nullOnDelete();
            }
        });

        // 3. Agregar configuración a scripts
        Schema::table('scripts', function (Blueprint $table) {
            if (!Schema::hasColumn('scripts', 'appointment_wa_confirmation')) {
                $table->boolean('appointment_wa_confirmation')->default(true)->after('voicebot_voice_id');
            }
            if (!Schema::hasColumn('scripts', 'appointment_wa_reminder')) {
                $table->boolean('appointment_wa_reminder')->default(true)->after('appointment_wa_confirmation');
            }
            if (!Schema::hasColumn('scripts', 'confirmation_wa_template_id')) {
                $table->foreignId('confirmation_wa_template_id')->nullable()->after('appointment_wa_reminder')
                    ->constrained('whatsapp_templates')->nullOnDelete();
            }
            if (!Schema::hasColumn('scripts', 'reminder_wa_template_id')) {
                $table->foreignId('reminder_wa_template_id')->nullable()->after('confirmation_wa_template_id')
                    ->constrained('whatsapp_templates')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropForeign(['confirmation_wa_template_id']);
            $table->dropForeign(['reminder_wa_template_id']);
            $table->dropColumn([
                'appointment_wa_confirmation',
                'appointment_wa_reminder',
                'confirmation_wa_template_id',
                'reminder_wa_template_id',
            ]);
        });

        Schema::table('dialer_campaigns', function (Blueprint $table) {
            $table->dropForeign(['confirmation_wa_template_id']);
            $table->dropForeign(['reminder_wa_template_id']);
            $table->dropColumn([
                'appointment_wa_confirmation',
                'appointment_wa_reminder',
                'confirmation_wa_template_id',
                'reminder_wa_template_id',
            ]);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn([
                'appointment_type',
                'wa_confirmation_sent_at',
                'wa_reminder_sent_at',
            ]);
        });
    }
};
