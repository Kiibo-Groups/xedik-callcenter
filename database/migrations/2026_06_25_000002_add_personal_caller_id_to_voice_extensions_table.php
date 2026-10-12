<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_extensions', function (Blueprint $table) {
            // FK to the authorized caller ID catalog.
            // Null = no personal caller ID; falls back to tenant default.
            $table->foreignId('personal_caller_id_id')
                ->nullable()
                ->after('caller_id_name')
                ->constrained('voice_caller_ids')
                ->nullOnDelete();

            // 'tenant' = caller_id_number was seeded/inherited from VoiceTenantSetting.default_caller_id
            // 'user'   = caller_id_number was set from personal_caller_id_id and must not be overwritten
            //            by future changes to the tenant default.
            $table->string('caller_id_source', 10)
                ->default('tenant')
                ->after('personal_caller_id_id');

            $table->index('personal_caller_id_id', 'voice_extensions_personal_cid_index');
        });
    }

    public function down(): void
    {
        Schema::table('voice_extensions', function (Blueprint $table) {
            $table->dropIndex('voice_extensions_personal_cid_index');
            $table->dropForeign(['personal_caller_id_id']);
            $table->dropColumn(['personal_caller_id_id', 'caller_id_source']);
        });
    }
};
