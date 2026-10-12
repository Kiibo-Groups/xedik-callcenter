<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->string('asterisk_linkedid', 64)->nullable()->after('call_uuid');
            $table->string('asterisk_queue_name', 128)->nullable()->after('asterisk_linkedid');
            $table->string('sip_call_id', 255)->nullable()->after('asterisk_queue_name');

            $table->unique(['tenant_id', 'asterisk_linkedid'], 'vc_tenant_linkedid_unique');
            $table->index(['tenant_id', 'sip_call_id'], 'vc_tenant_sip_call_id_idx');
            $table->index(['tenant_id', 'asterisk_queue_name'], 'vc_tenant_queue_name_idx');
            $table->index(['tenant_id', 'direction'], 'vc_tenant_direction_idx');
            $table->index(['tenant_id', 'created_at'], 'vc_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropUnique('vc_tenant_linkedid_unique');
            $table->dropIndex('vc_tenant_sip_call_id_idx');
            $table->dropIndex('vc_tenant_queue_name_idx');
            $table->dropIndex('vc_tenant_direction_idx');
            $table->dropIndex('vc_tenant_created_idx');

            $table->dropColumn([
                'asterisk_linkedid',
                'asterisk_queue_name',
                'sip_call_id',
            ]);
        });
    }
};
