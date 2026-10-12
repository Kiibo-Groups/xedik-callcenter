<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            // Declarative call state reported by the browser (JsSIP).
            // NULL means the session pre-dates this field — interpreted as 'unknown'.
            // 'no_call' is explicit for new sessions created after this migration.
            // Not a SQL ENUM: avoids ALTER TABLE pain on both SQLite and MySQL
            // and allows adding new states without a schema change.
            $table->string('call_state', 20)->nullable()->after('failure_reason');

            // Set when the first active call state (ringing/connecting/active/held)
            // is recorded.  Preserved (not cleared) when returning to no_call —
            // kept for audit of the last call's start time.
            $table->timestamp('call_started_at')->nullable()->after('call_state');

            // Updated every time call_state changes.  Used to determine freshness.
            $table->timestamp('call_state_updated_at')->nullable()->after('call_started_at');

            $table->index(['tenant_id', 'user_id', 'call_state'],
                'voice_sessions_tenant_user_call_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->dropIndex('voice_sessions_tenant_user_call_state_idx');
            $table->dropColumn(['call_state', 'call_started_at', 'call_state_updated_at']);
        });
    }
};
