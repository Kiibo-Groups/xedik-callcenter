<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['assigned_to', 'status']);
            $table->index('omni_queue_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations_and_messages', function (Blueprint $table) {
            //
        });
    }
};
