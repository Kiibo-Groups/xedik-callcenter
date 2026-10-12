<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            try {
                DB::statement('ALTER TABLE calls ADD COLUMN recording_id VARCHAR NULL');
            } finally {
                DB::statement('PRAGMA foreign_keys=ON');
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (!Schema::hasColumn('calls', 'recording_id')) {
                Schema::table('calls', function (Blueprint $table) {
                    $table->string('recording_id')->nullable();
                });
            }

            return;
        }

        throw new \RuntimeException("Unsupported database driver [{$driver}] for calls recording_id migration.");
    }

    public function down(): void {}
};
