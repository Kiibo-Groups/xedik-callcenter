<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Override Caller ID for outbound calls from this campaign
            $table->string('outbound_caller_id', 40)->nullable()->after('description');
            // Optional gateway override; inherits from tenant default when null
            $table->foreignId('voice_gateway_id')
                ->nullable()
                ->after('outbound_caller_id')
                ->constrained('voice_gateways')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_gateway_id');
            $table->dropColumn('outbound_caller_id');
        });
    }
};
