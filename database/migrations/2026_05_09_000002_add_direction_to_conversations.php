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

        if (!Schema::hasColumn('conversations', 'direction')) {
            if ($driver === 'sqlite') {
                DB::statement("ALTER TABLE conversations ADD COLUMN direction VARCHAR(10) DEFAULT NULL");
            } else {
                Schema::table('conversations', function (Blueprint $table) {
                    $table->string('direction', 10)->nullable()->after('channel');
                });
            }
        }

        // Backfill: tomar direction del primer mensaje de cada conversación
        $conversations = DB::table('conversations')->get(['id']);
        foreach ($conversations as $conv) {
            $firstMsg = DB::table('messages')
                ->where('conversation_id', $conv->id)
                ->orderBy('sent_at', 'asc')
                ->first(['direction']);
            if ($firstMsg) {
                DB::table('conversations')
                    ->where('id', $conv->id)
                    ->update(['direction' => $firstMsg->direction]);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'sqlite' && Schema::hasColumn('conversations', 'direction')) {
            Schema::table('conversations', fn(Blueprint $t) => $t->dropColumn('direction'));
        }
    }
};
