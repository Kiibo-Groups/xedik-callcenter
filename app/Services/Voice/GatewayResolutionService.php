<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\Campaign;
use App\Models\DialerCampaign;
use App\Models\VoiceGateway;
use App\Models\VoiceTenantSetting;

/**
 * Resolves the outbound VoiceGateway for a Preview Dialer call.
 *
 * Precedence:
 *   1. Parent CRM Campaign gateway override (campaigns.voice_gateway_id)
 *   2. Tenant voice setting gateway (voice_tenant_settings.voice_gateway_id)
 *   3. Tenant-accessible default gateway (VoiceGateway::accessibleBy()->where('is_default', true))
 *   4. None — callers must treat a null result as "no prefix, dial as before".
 *
 * All lookups are explicitly tenant-scoped (never relies on the currently
 * authenticated user's implicit tenant scope) so a gateway belonging to
 * another tenant can never be resolved here.
 *
 * The dial prefix sent to Asterisk (X-xedik-Dial-Prefix) is built as
 * hidden_prefix + country_code (see buildDialPrefix()) — the trunk/provider
 * access prefix followed by the destination country code. The lead's visible
 * phone number is never modified.
 *
 * NOT YET VALIDATED: gateway.national_number_length vs. the lead phone's
 * actual digit count. A mismatch will not block the call today — flagged as
 * a future hardening step, not enforced by this service.
 */
class GatewayResolutionService
{
    public function resolveForDialerCampaign(DialerCampaign $dialerCampaign): ?VoiceGateway
    {
        $tenantId = (int) $dialerCampaign->tenant_id;

        return $this->campaignGateway($dialerCampaign, $tenantId)
            ?? $this->tenantSettingGateway($tenantId)
            ?? $this->accessibleDefaultGateway($tenantId);
    }

    public function resolveForCampaign(Campaign $campaign): ?VoiceGateway
    {
        $tenantId = (int) $campaign->tenant_id;

        return $this->accessibleOrNull($campaign->voiceGateway, $tenantId)
            ?? $this->tenantSettingGateway($tenantId)
            ?? $this->accessibleDefaultGateway($tenantId);
    }

    public function resolveDialPrefixForDialerCampaign(DialerCampaign $dialerCampaign): ?string
    {
        $gateway = $this->resolveForDialerCampaign($dialerCampaign);

        return $gateway ? $this->buildDialPrefix($gateway) : null;
    }

    public function resolveDialPrefixForCampaign(Campaign $campaign): ?string
    {
        $gateway = $this->resolveForCampaign($campaign);

        return $gateway ? $this->buildDialPrefix($gateway) : null;
    }

    /**
     * Final SIP dial prefix sent to Asterisk as X-xedik-Dial-Prefix:
     * trunk/provider access prefix (hidden_prefix) + destination country code
     * (country_code). Either half may be missing; both missing -> null.
     * Does not touch the lead's visible phone number.
     */
    public function buildDialPrefix(VoiceGateway $gateway): ?string
    {
        $hiddenPrefix = trim((string) $gateway->hidden_prefix);
        $countryCode  = trim((string) $gateway->country_code);

        $combined = $hiddenPrefix . $countryCode;

        return $combined !== '' ? $combined : null;
    }

    private function campaignGateway(DialerCampaign $dialerCampaign, int $tenantId): ?VoiceGateway
    {
        $campaign = Campaign::withoutGlobalScopes()
            ->where('id', $dialerCampaign->campaign_id)
            ->where('tenant_id', $tenantId)
            ->first();

        return $this->accessibleOrNull($campaign?->voiceGateway, $tenantId);
    }

    private function tenantSettingGateway(int $tenantId): ?VoiceGateway
    {
        $setting = VoiceTenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return $this->accessibleOrNull($setting?->voiceGateway, $tenantId);
    }

    private function accessibleDefaultGateway(int $tenantId): ?VoiceGateway
    {
        return VoiceGateway::accessibleBy($tenantId)
            ->where('is_default', true)
            ->first();
    }

    private function accessibleOrNull(?VoiceGateway $gateway, int $tenantId): ?VoiceGateway
    {
        return ($gateway && $gateway->isAccessibleByTenant($tenantId)) ? $gateway : null;
    }
}
