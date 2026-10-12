<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('call_uuid');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
    }
};
