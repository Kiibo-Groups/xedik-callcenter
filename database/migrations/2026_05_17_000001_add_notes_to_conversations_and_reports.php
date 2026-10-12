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
        $driver = DB::getDriverName();

        if (!Schema::hasColumn('conversations', 'notes')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE conversations ADD COLUMN notes TEXT DEFAULT NULL');
            } else {
                Schema::table('conversations', fn(Blueprint $t) => $t->text('notes')->nullable());
            }
        }

        if (!Schema::hasColumn('conversation_reports', 'notes')) {
            if ($driver === 'sqlite') {
                DB::statement('ALTER TABLE conversation_reports ADD COLUMN notes TEXT DEFAULT NULL');
            } else {
                Schema::table('conversation_reports', fn(Blueprint $t) => $t->text('notes')->nullable());
            }
        }
    }

    public function down(): void
    {
        // SQLite no soporta DROP COLUMN — omitir en down
        if (DB::getDriverName() === 'sqlite') return;

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
