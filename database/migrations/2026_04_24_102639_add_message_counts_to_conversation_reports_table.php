<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->unsignedInteger('inbound_message_count')->default(0)->after('message_count');
            $table->unsignedInteger('outbound_message_count')->default(0)->after('inbound_message_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_reports', function (Blueprint $table) {
            $table->dropColumn(['inbound_message_count', 'outbound_message_count']);
        });
    }
};
