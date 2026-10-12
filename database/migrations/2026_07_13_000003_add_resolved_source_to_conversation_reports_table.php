<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->string('resolved_source', 30)->nullable()->after('disposition');
        });

        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->index('resolved_source');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->dropIndex(['resolved_source']);
            $table->dropColumn('resolved_source');
        });
    }
};
