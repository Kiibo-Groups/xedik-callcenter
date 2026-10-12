<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DialerCampaign;
use App\Models\VoiceCallerId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallerIdResolutionService
{
    /**
     * Select the next eligible Caller ID for a campaign using LRU rotation.
     *
     * Selection rules (all must pass):
     *   - dialer_campaign_caller_ids.dialer_campaign_id = campaign
     *   - dialer_campaign_caller_ids.tenant_id = campaign tenant (cross-tenant guard)
     *   - dialer_campaign_caller_ids.is_active = true
     *   - voice_caller_ids.is_active = true
     *   - voice_caller_ids.is_verified = true
     *   - voice_caller_ids.tenant_id = campaign tenant
     *
     * Rotation: NULL last_used_at first (never used), then ascending last_used_at.
     * Tie-break: lowest assignment id for determinism.
     *
     * Uses lockForUpdate so concurrent agents on the same campaign serialize safely.
     * Returns null (never throws) when the campaign pool is empty or has no valid entries.
     */
    public function resolveForCampaign(DialerCampaign $campaign): ?VoiceCallerId
    {
        return DB::transaction(function () use ($campaign): ?VoiceCallerId {
            $assignment = DB::table('dialer_campaign_caller_ids as dcid')
                ->join('voice_caller_ids as vc', 'dcid.voice_caller_id_id', '=', 'vc.id')
                ->where('dcid.dialer_campaign_id', $campaign->id)
                ->where('dcid.tenant_id', $campaign->tenant_id)
                ->where('dcid.is_active', true)
                ->where('vc.is_active', true)
                ->where('vc.is_verified', true)
                ->where('vc.tenant_id', $campaign->tenant_id)
                ->select('dcid.id', 'dcid.voice_caller_id_id')
                ->orderBy('dcid.last_used_at', 'asc')  // NULL sorts first in both MySQL and SQLite
                ->orderBy('dcid.id', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$assignment) {
                Log::warning('CallerIdResolutionService: no valid caller ID for campaign', [
                    'dialer_campaign_id' => $campaign->id,
                    'tenant_id'          => $campaign->tenant_id,
                ]);
                return null;
            }

            DB::table('dialer_campaign_caller_ids')
                ->where('id', $assignment->id)
                ->update(['last_used_at' => now()]);

            return VoiceCallerId::withoutGlobalScopes()
                ->find($assignment->voice_caller_id_id);
        });
    }
}
