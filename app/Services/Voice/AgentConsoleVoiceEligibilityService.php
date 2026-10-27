<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\User;
use App\Models\VoiceSession;

final class AgentConsoleVoiceEligibilityService
{
    /** @return array<string, bool> */
    public function capability(User $user): array
    {
        $agentConsoleConfig = config('voice.agent_console', []);
        $tenantIds = array_map(
            'intval',
            $agentConsoleConfig['tenant_ids'] ?? []
        );
        $userIds = array_map(
            'intval',
            $agentConsoleConfig['user_ids'] ?? []
        );
        $tenantAllowed = empty($tenantIds) || in_array((int) $user->tenant_id, $tenantIds, true);
        $userAllowed = empty($userIds) || in_array((int) $user->id, $userIds, true);
        $qaEnabled = (bool) ($agentConsoleConfig['enabled'] ?? false)
            && $tenantAllowed
            && $userAllowed;

        if (!$qaEnabled || !config('voice.enabled')) {
            return $this->disabledCapability();
        }

        $tenantSetting = $user->tenant?->voiceTenantSetting;
        $extension = $user->voiceExtension;
        $eligible = (bool) $tenantSetting?->voice_enabled
            && $extension !== null
            && (bool) $extension->is_active
            && $extension->sip_provisioning_status === 'provisioned';

        return [
            'enabled' => true,
            'eligible' => $eligible,
            'allow_outbound' => $eligible
                && (bool) $extension->allow_outbound,
            'allow_internal' => $eligible
                && (bool) $extension->allow_internal,
            'allow_transfer' => $eligible
                && (bool) $extension->allow_transfer,
            'qa_enabled' => true,
        ];
    }

    public function requiresReadyVoiceGuard(User $user): bool
    {
        return $this->capability($user)['eligible'];
    }

    public function hasOperationalSessionForTab(
        User $user,
        ?string $browserTabId
    ): bool {
        if (!is_string($browserTabId) || $browserTabId === '') {
            return false;
        }

        $currentSession = VoiceSession::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (!$currentSession
            || !in_array(
                $currentSession->status,
                ['registered', 'active'],
                true
            )
            || !hash_equals(
                (string) $currentSession->browser_tab_id,
                $browserTabId
            )
            || !$currentSession->expires_at
            || !$currentSession->expires_at->isFuture()) {
            return false;
        }

        return true;
    }

    /** @return array<string, bool> */
    private function disabledCapability(): array
    {
        return [
            'enabled' => false,
            'eligible' => false,
            'allow_outbound' => false,
            'allow_internal' => false,
            'allow_transfer' => false,
            'qa_enabled' => false,
        ];
    }
}
