<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->foreignId('voice_caller_id_id')
                ->nullable()
                ->after('from_number')
                ->constrained('voice_caller_ids')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropForeign(['voice_caller_id_id']);
            $table->dropColumn('voice_caller_id_id');
        });
    }
};
