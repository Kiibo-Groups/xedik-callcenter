<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // lead_sources uses 'order' column (not 'sort_order') — verified in create_lead_sources_table migration
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            DB::table('lead_sources')->insertOrIgnore([
                'tenant_id'  => $tenantId,
                'name'       => 'Webchat',
                'is_active'  => true,
                'order'      => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
