<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attachments') || !Schema::hasTable('messages')) {
            return;
        }

        if ($this->foreignKeyExists('attachments', 'attachments_message_id_foreign')) {
            return;
        }

        Schema::table('attachments', function (Blueprint $table) {
            $table->foreign('message_id', 'attachments_message_id_foreign')
                ->references('id')
                ->on('messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('attachments') && $this->foreignKeyExists('attachments', 'attachments_message_id_foreign')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropForeign('attachments_message_id_foreign');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                ->exists();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select('PRAGMA foreign_key_list(attachments)'))
                ->contains(fn ($row) => ($row->from ?? null) === 'message_id' && ($row->table ?? null) === 'messages');
        }

        throw new \RuntimeException("Unsupported database driver [{$driver}] for attachments message FK migration.");
    }
};
