<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            $table->foreignId('agent_user_id')
                ->nullable()
                ->after('voice_session_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'agent_user_id'], 'vc_tenant_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table): void {
            $table->dropIndex('vc_tenant_agent_idx');
            $table->dropForeign(['agent_user_id']);
            $table->dropColumn('agent_user_id');
        });
    }
};
