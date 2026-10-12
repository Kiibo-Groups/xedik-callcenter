<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\VoiceTenantSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure every existing tenant has a VoiceTenantSetting record (disabled by default)
        Tenant::all()->each(function (Tenant $tenant) {
            VoiceTenantSetting::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'voice_enabled'          => false,
                    'session_ttl_seconds'    => (int) config('voice.session_ttl_seconds', 28800),
                    'max_sessions_per_agent' => (int) config('voice.max_sessions_per_agent', 1),
                ]
            );
        });
    }

    public function down(): void
    {
        // Non-destructive backfill — nothing to undo
    }
};
