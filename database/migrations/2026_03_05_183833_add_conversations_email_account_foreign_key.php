<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('conversations') || !Schema::hasTable('email_accounts')) {
            return;
        }

        if ($this->foreignKeyExists('conversations', 'conversations_email_account_id_foreign')) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('email_account_id', 'conversations_email_account_id_foreign')
                ->references('id')
                ->on('email_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && $this->foreignKeyExists('conversations', 'conversations_email_account_id_foreign')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropForeign('conversations_email_account_id_foreign');
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
            return collect(DB::select('PRAGMA foreign_key_list(conversations)'))
                ->contains(fn ($row) => ($row->from ?? null) === 'email_account_id' && ($row->table ?? null) === 'email_accounts');
        }

        throw new \RuntimeException("Unsupported database driver [{$driver}] for conversations email_account FK migration.");
    }
};
