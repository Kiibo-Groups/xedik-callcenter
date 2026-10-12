<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->boolean('active_slot')->nullable()->after('status');
        });

        DB::table('preview_dialer_offers')
            ->whereIn('status', ['pending', 'accepted'])
            ->update(['active_slot' => 1]);

        DB::table('preview_dialer_offers')
            ->whereIn('status', ['declined', 'expired'])
            ->update(['active_slot' => null]);

        $duplicateUserSlots = DB::table('preview_dialer_offers')
            ->select('tenant_id', 'user_id', DB::raw('COUNT(*) as aggregate'))
            ->where('active_slot', 1)
            ->groupBy('tenant_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateUserSlots) {
            throw new RuntimeException('Cannot migrate preview_dialer_offers: duplicate active offers exist for the same tenant/user.');
        }

        $duplicateLeadSlots = DB::table('preview_dialer_offers')
            ->select('lead_list_id', 'lead_list_row_id', DB::raw('COUNT(*) as aggregate'))
            ->where('active_slot', 1)
            ->groupBy('lead_list_id', 'lead_list_row_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateLeadSlots) {
            throw new RuntimeException('Cannot migrate preview_dialer_offers: duplicate active offers exist for the same lead row.');
        }

        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->index('tenant_id', 'pdo_tenant_fk_idx');
            $table->index('user_id', 'pdo_user_fk_idx');
            $table->index('lead_list_id', 'pdo_lead_list_fk_idx');
        });

        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropUnique('pdo_tenant_user_unique');
            $table->dropUnique('pdo_lead_row_unique');
        });

        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'user_id', 'active_slot'], 'pdo_tenant_user_slot_unique');
            $table->unique(['lead_list_id', 'lead_list_row_id', 'active_slot'], 'pdo_lead_row_slot_unique');
        });
    }

    public function down(): void
    {
        $historicalUserDuplicates = DB::table('preview_dialer_offers')
            ->select('tenant_id', 'user_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('tenant_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($historicalUserDuplicates) {
            throw new RuntimeException('Cannot rollback active_slot: historical offers would violate the original tenant/user unique index.');
        }

        $historicalLeadDuplicates = DB::table('preview_dialer_offers')
            ->select('lead_list_id', 'lead_list_row_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('lead_list_id', 'lead_list_row_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($historicalLeadDuplicates) {
            throw new RuntimeException('Cannot rollback active_slot: historical offers would violate the original lead row unique index.');
        }

        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->dropUnique('pdo_tenant_user_slot_unique');
            $table->dropUnique('pdo_lead_row_slot_unique');
        });

        Schema::table('preview_dialer_offers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'user_id'], 'pdo_tenant_user_unique');
            $table->unique(['lead_list_id', 'lead_list_row_id'], 'pdo_lead_row_unique');
            $table->dropColumn('active_slot');
        });
    }
};
