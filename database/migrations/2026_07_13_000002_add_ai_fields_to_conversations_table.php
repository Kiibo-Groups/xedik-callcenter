<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('ai_bot_active')->default(false)->after('disposition');
            $table->string('resolved_source', 30)->nullable()->after('resolved_at'); // human | ai | system
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('ai_bot_active');
            $table->index('resolved_source');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['ai_bot_active']);
            $table->dropIndex(['resolved_source']);
            $table->dropColumn(['ai_bot_active', 'resolved_source']);
        });
    }
};
