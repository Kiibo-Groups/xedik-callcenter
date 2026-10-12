<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('omni_queue_user') || !Schema::hasTable('omni_queues')) {
            return;
        }

        if ($this->foreignKeyExists('omni_queue_user', 'omni_queue_user_omni_queue_id_foreign')) {
            return;
        }

        Schema::table('omni_queue_user', function (Blueprint $table) {
            $table->foreign('omni_queue_id', 'omni_queue_user_omni_queue_id_foreign')
                ->references('id')
                ->on('omni_queues')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('omni_queue_user') && $this->foreignKeyExists('omni_queue_user', 'omni_queue_user_omni_queue_id_foreign')) {
            Schema::table('omni_queue_user', function (Blueprint $table) {
                $table->dropForeign('omni_queue_user_omni_queue_id_foreign');
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
            return collect(DB::select("PRAGMA foreign_key_list({$table})"))
                ->contains(fn ($row) => ($row->from ?? null) === 'omni_queue_id' && ($row->table ?? null) === 'omni_queues');
        }

        throw new \RuntimeException("Unsupported database driver [{$driver}] for omni_queue_user FK migration.");
    }
};
