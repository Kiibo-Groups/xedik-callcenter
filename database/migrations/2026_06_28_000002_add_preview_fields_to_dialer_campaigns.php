<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('dialer_campaigns', 'script_id')) {
                $table->foreignId('script_id')
                    ->nullable()
                    ->after('lead_list_id')
                    ->constrained('scripts')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('dialer_campaigns', 'offer_timeout_seconds')) {
                $table->unsignedInteger('offer_timeout_seconds')
                    ->default(60)
                    ->after('dial_block_size');
            }

            if (!Schema::hasColumn('dialer_campaigns', 'started_at')) {
                $table->timestamp('started_at')
                    ->nullable()
                    ->after('status');
            }

            $table->index(['dialer_type', 'status'], 'dc_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dialer_campaigns', function (Blueprint $table) {
            $table->dropIndex('dc_type_status_idx');

            if (Schema::hasColumn('dialer_campaigns', 'script_id')) {
                $table->dropConstrainedForeignId('script_id');
            }

            if (Schema::hasColumn('dialer_campaigns', 'offer_timeout_seconds')) {
                $table->dropColumn('offer_timeout_seconds');
            }

            if (Schema::hasColumn('dialer_campaigns', 'started_at')) {
                $table->dropColumn('started_at');
            }
        });
    }
};
