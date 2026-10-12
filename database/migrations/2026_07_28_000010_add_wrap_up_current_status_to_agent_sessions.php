<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE agent_sessions RENAME TO agent_sessions_old');

            DB::statement("
                CREATE TABLE agent_sessions (
                    id integer primary key autoincrement not null,
                    user_id integer not null,
                    status varchar check (status in ('ready','paused','wrap_up','offline')) not null default 'offline',
                    pause_reason_id integer,
                    pending_status_after_wrap_up varchar check (pending_status_after_wrap_up in ('ready','paused','offline')),
                    pending_pause_reason_id integer,
                    status_since datetime,
                    active_emails integer not null default 0,
                    active_chats integer not null default 0,
                    last_ping_at datetime,
                    created_at datetime,
                    updated_at datetime,
                    tenant_id integer default null,
                    foreign key(user_id) references users(id) on delete cascade,
                    foreign key(pause_reason_id) references pause_reasons(id) on delete set null,
                    foreign key(pending_pause_reason_id) references pause_reasons(id) on delete set null
                )
            ");

            DB::statement("
                INSERT INTO agent_sessions (
                    id, user_id, status, pause_reason_id, status_since,
                    active_emails, active_chats, last_ping_at, created_at,
                    updated_at, tenant_id
                )
                SELECT
                    id, user_id, status, pause_reason_id, status_since,
                    active_emails, active_chats, last_ping_at, created_at,
                    updated_at, tenant_id
                FROM agent_sessions_old
            ");

            DB::statement('DROP TABLE agent_sessions_old');

            Schema::table('agent_sessions', function (Blueprint $table): void {
                $table->unique('user_id');
                $table->index('status');
                $table->index('pending_status_after_wrap_up');
            });

            return;
        }

        DB::statement("
            ALTER TABLE agent_sessions
            MODIFY COLUMN status ENUM('ready','paused','wrap_up','offline') NOT NULL DEFAULT 'offline'
        ");

        Schema::table('agent_sessions', function (Blueprint $table): void {
            $table->enum('pending_status_after_wrap_up', ['ready', 'paused', 'offline'])
                ->nullable()
                ->after('pause_reason_id');
            $table->foreignId('pending_pause_reason_id')
                ->nullable()
                ->after('pending_status_after_wrap_up')
                ->constrained('pause_reasons')
                ->nullOnDelete();
            $table->index('pending_status_after_wrap_up');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE agent_sessions RENAME TO agent_sessions_new');

            DB::statement("
                CREATE TABLE agent_sessions (
                    id integer primary key autoincrement not null,
                    user_id integer not null,
                    status varchar check (status in ('ready','paused','offline')) not null default 'offline',
                    pause_reason_id integer,
                    status_since datetime,
                    active_emails integer not null default 0,
                    active_chats integer not null default 0,
                    last_ping_at datetime,
                    created_at datetime,
                    updated_at datetime,
                    tenant_id integer default null,
                    foreign key(user_id) references users(id) on delete cascade,
                    foreign key(pause_reason_id) references pause_reasons(id) on delete set null
                )
            ");

            DB::statement("
                INSERT INTO agent_sessions (
                    id, user_id, status, pause_reason_id, status_since,
                    active_emails, active_chats, last_ping_at, created_at,
                    updated_at, tenant_id
                )
                SELECT
                    id,
                    user_id,
                    CASE WHEN status = 'wrap_up' THEN 'offline' ELSE status END,
                    CASE WHEN status = 'wrap_up' THEN null ELSE pause_reason_id END,
                    status_since,
                    active_emails,
                    active_chats,
                    last_ping_at,
                    created_at,
                    updated_at,
                    tenant_id
                FROM agent_sessions_new
            ");

            DB::statement('DROP TABLE agent_sessions_new');

            Schema::table('agent_sessions', function (Blueprint $table): void {
                $table->unique('user_id');
                $table->index('status');
            });

            return;
        }

        Schema::table('agent_sessions', function (Blueprint $table): void {
            $table->dropIndex(['pending_status_after_wrap_up']);
            $table->dropConstrainedForeignId('pending_pause_reason_id');
            $table->dropColumn('pending_status_after_wrap_up');
        });

        DB::statement("
            UPDATE agent_sessions
            SET status = 'offline', pause_reason_id = null
            WHERE status = 'wrap_up'
        ");

        DB::statement("
            ALTER TABLE agent_sessions
            MODIFY COLUMN status ENUM('ready','paused','offline') NOT NULL DEFAULT 'offline'
        ");
    }
};
