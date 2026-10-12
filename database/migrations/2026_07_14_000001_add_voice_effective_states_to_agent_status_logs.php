<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends agent_status_logs.status to record Voice EFFECTIVE occupancy states
 * (ringing/in_call/wrap_up) alongside the existing requested states
 * (ready/paused/offline), and adds two small, nullable traceability columns:
 *
 *   voice_call_id — correlates a ringing/in_call/wrap_up row with the exact
 *                   VoiceCall that caused it (nullOnDelete — never blocks
 *                   deletion of a VoiceCall, never rewrites history).
 *   source        — distinguishes manual/voice/logout/stale/takeover/system
 *                   transitions for reporting. Short string, no payloads.
 *
 * Requested-state semantics (ready/paused/offline) and AgentSession are
 * untouched by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite enforces the enum via a CHECK constraint that cannot be
            // altered with ALTER TABLE — rebuild the table (standard SQLite
            // technique), preserving every existing row unmodified.
            DB::statement('ALTER TABLE agent_status_logs RENAME TO agent_status_logs_old');

            DB::statement("
                CREATE TABLE agent_status_logs (
                    id integer primary key autoincrement not null,
                    user_id integer not null,
                    status varchar check (status in ('ready','paused','offline','ringing','in_call','wrap_up')) not null,
                    pause_reason_id integer,
                    voice_call_id integer,
                    source varchar,
                    started_at datetime not null,
                    ended_at datetime,
                    duration_seconds integer,
                    created_at datetime,
                    updated_at datetime,
                    tenant_id integer default null,
                    foreign key(user_id) references users(id) on delete cascade,
                    foreign key(pause_reason_id) references pause_reasons(id) on delete set null,
                    foreign key(voice_call_id) references voice_calls(id) on delete set null
                )
            ");

            DB::statement('
                INSERT INTO agent_status_logs (
                    id, user_id, status, pause_reason_id, started_at, ended_at,
                    duration_seconds, created_at, updated_at, tenant_id
                )
                SELECT
                    id, user_id, status, pause_reason_id, started_at, ended_at,
                    duration_seconds, created_at, updated_at, tenant_id
                FROM agent_status_logs_old
            ');

            DB::statement('DROP TABLE agent_status_logs_old');

            Schema::table('agent_status_logs', function ($table) {
                $table->index(['user_id', 'started_at']);
                $table->index(['tenant_id', 'user_id', 'ended_at']);
                $table->index('voice_call_id');
            });

            return;
        }

        DB::statement("
            ALTER TABLE agent_status_logs
            MODIFY COLUMN status ENUM('ready','paused','offline','ringing','in_call','wrap_up') NOT NULL
        ");

        Schema::table('agent_status_logs', function ($table) {
            $table->unsignedBigInteger('voice_call_id')->nullable()->after('pause_reason_id');
            $table->string('source', 32)->nullable()->after('voice_call_id');
        });

        Schema::table('agent_status_logs', function ($table) {
            $table->foreign('voice_call_id')->references('id')->on('voice_calls')->nullOnDelete();
            $table->index(['tenant_id', 'user_id', 'ended_at']);
            $table->index('voice_call_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE agent_status_logs RENAME TO agent_status_logs_new');

            DB::statement("
                CREATE TABLE agent_status_logs (
                    id integer primary key autoincrement not null,
                    user_id integer not null,
                    status varchar check (status in ('ready','paused','offline')) not null,
                    pause_reason_id integer,
                    started_at datetime not null,
                    ended_at datetime,
                    duration_seconds integer,
                    created_at datetime,
                    updated_at datetime,
                    tenant_id integer default null,
                    foreign key(user_id) references users(id) on delete cascade,
                    foreign key(pause_reason_id) references pause_reasons(id) on delete set null
                )
            ");

            DB::statement("
                INSERT INTO agent_status_logs (
                    id, user_id, status, pause_reason_id, started_at, ended_at,
                    duration_seconds, created_at, updated_at, tenant_id
                )
                SELECT
                    id, user_id, status, pause_reason_id, started_at, ended_at,
                    duration_seconds, created_at, updated_at, tenant_id
                FROM agent_status_logs_new
                WHERE status IN ('ready','paused','offline')
            ");

            DB::statement('DROP TABLE agent_status_logs_new');

            Schema::table('agent_status_logs', function ($table) {
                $table->index(['user_id', 'started_at']);
            });

            return;
        }

        Schema::table('agent_status_logs', function ($table) {
            $table->dropForeign(['voice_call_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'ended_at']);
            $table->dropColumn(['voice_call_id', 'source']);
        });

        DB::statement("
            ALTER TABLE agent_status_logs
            MODIFY COLUMN status ENUM('ready','paused','offline') NOT NULL
        ");
    }
};
