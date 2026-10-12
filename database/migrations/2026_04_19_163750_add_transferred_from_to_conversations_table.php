<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('transferred_from')->nullable()->after('assigned_to');
            $table->foreign('transferred_from')->references('id')->on('users')->nullOnDelete();
            $table->index('transferred_from');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['transferred_from']);
            $table->dropIndex(['transferred_from']);
            $table->dropColumn('transferred_from');
        });
    }
};
