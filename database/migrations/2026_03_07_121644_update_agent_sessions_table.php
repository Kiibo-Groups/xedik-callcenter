<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropIndex('agent_sessions_status_index');
            $table->dropColumn('status');
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->enum('status', ['ready', 'paused', 'offline'])
                ->default('offline')
                ->after('user_id');
            $table->foreignId('pause_reason_id')
                ->nullable()
                ->after('status')
                ->constrained('pause_reasons')
                ->nullOnDelete();
            $table->timestamp('status_since')
                ->nullable()
                ->after('pause_reason_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pause_reason_id');
            $table->dropColumn(['status', 'status_since']);
            $table->enum('status', ['online', 'away', 'offline'])->default('offline');
            $table->index('status');
        });
    }
};
