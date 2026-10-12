<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('voice_calls', 'preview_attempt_counted_at')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                $table->timestamp('preview_attempt_counted_at')
                    ->nullable()
                    ->after('hangup_cause');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('voice_calls', 'preview_attempt_counted_at')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                $table->dropColumn('preview_attempt_counted_at');
            });
        }
    }
};
