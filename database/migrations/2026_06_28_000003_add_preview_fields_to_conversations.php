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
            if (!Schema::hasColumn('conversations', 'dialer_campaign_id')) {
                $table->foreignId('dialer_campaign_id')
                    ->nullable()
                    ->after('script_id')
                    ->constrained('dialer_campaigns')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('conversations', 'lead_list_id')) {
                $table->foreignId('lead_list_id')
                    ->nullable()
                    ->after('dialer_campaign_id')
                    ->constrained('lead_lists')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('conversations', 'lead_list_row_id')) {
                $table->unsignedBigInteger('lead_list_row_id')
                    ->nullable()
                    ->after('lead_list_id');
                $table->index('lead_list_row_id', 'conv_lead_list_row_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'lead_list_row_id')) {
                $table->dropIndex('conv_lead_list_row_idx');
                $table->dropColumn('lead_list_row_id');
            }

            if (Schema::hasColumn('conversations', 'lead_list_id')) {
                $table->dropConstrainedForeignId('lead_list_id');
            }

            if (Schema::hasColumn('conversations', 'dialer_campaign_id')) {
                $table->dropConstrainedForeignId('dialer_campaign_id');
            }
        });
    }
};
