<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\AgentSession;
use App\Models\VoiceSession;

/**
 * Single source of truth for Voice ownership policy decisions.
 *
 * Used by both VoiceSessionService::ownership() and VoiceSessionService::takeOver()
 * so that policy is never evaluated twice with potentially different results.
 *
 * Policy values:
 *   direct   → no active session exists; start fresh
 *   reconnect → same browser tab; reconnect to existing session
 *   silent   → other tab, safe to take over without confirmation
 *   confirm  → other tab, user must explicitly confirm
 *   blocked  → other tab, active call detected; do not take over
 *
 * Reasons encode the specific condition that drove the policy choice and are
 * included in API responses for frontend UX decisions and logging.
 */
class VoiceOwnershipPolicyService
{
    /**
     * @return array{
     *   ownership: 'none'|'same_tab'|'other_tab',
     *   policy: 'direct'|'reconnect'|'silent'|'confirm'|'blocked',
     *   reason: string,
     *   call_state: string,
     *   call_state_is_fresh: bool,
     *   agent_status: 'offline'|'ready'|'paused'|'unknown',
     *   session: VoiceSession|null,
     * }
     */
    public function evaluate(?VoiceSession $session, string $requestingBrowserTabId): array
    {
        // ── No session ────────────────────────────────────────────────────────
        if (!$session) {
            return [
                'ownership'          => 'none',
                'policy'             => 'direct',
                'reason'             => 'no_session',
                'call_state'         => VoiceSession::CALL_STATE_UNKNOWN,
                'call_state_is_fresh'=> false,
                'agent_status'       => 'unknown',
                'session'            => null,
            ];
        }

        // ── Same tab: reconnect ────────────────────────────────────────────
        if (hash_equals((string) $session->browser_tab_id, $requestingBrowserTabId)) {
            return [
                'ownership'          => 'same_tab',
                'policy'             => 'reconnect',
                'reason'             => 'same_browser_tab',
                'call_state'         => $session->effectiveCallState(),
                'call_state_is_fresh'=> true,
                'agent_status'       => 'unknown',
                'session'            => $session,
            ];
        }

        // ── Other tab: evaluate call state + agent status ──────────────────
        $freshSeconds       = (int) config('voice.call_state_fresh_seconds', 120);
        $effectiveCallState = $session->effectiveCallState();
        $isFresh            = $this->isCallStateFresh($session, $freshSeconds);
        $agentStatus        = $this->agentStatus($session);

        // Safety gate: while the frontend is not yet reporting call states,
        // disable silent and blocked policies to avoid false negatives.
        // other_tab always produces confirm until explicitly enabled.
        if (!config('voice.call_state_policy_enabled', false)) {
            return [
                'ownership'          => 'other_tab',
                'policy'             => 'confirm',
                'reason'             => 'call_state_policy_disabled',
                'call_state'         => $effectiveCallState,
                'call_state_is_fresh'=> $isFresh,
                'agent_status'       => $agentStatus,
                'session'            => $session,
            ];
        }

        [$policy, $reason] = $this->computePolicy($effectiveCallState, $isFresh, $agentStatus);

        return [
            'ownership'          => 'other_tab',
            'policy'             => $policy,
            'reason'             => $reason,
            'call_state'         => $effectiveCallState,
            'call_state_is_fresh'=> $isFresh,
            'agent_status'       => $agentStatus,
            'session'            => $session,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array{string, string} [policy, reason] */
    private function computePolicy(string $callState, bool $isFresh, string $agentStatus): array
    {
        // Active call states → blocked (if fresh) or confirm (if stale)
        if (in_array($callState, VoiceSession::ACTIVE_CALL_STATES, true)) {
            return $isFresh
                ? ['blocked', "other_tab_{$callState}"]
                : ['confirm', 'other_tab_stale_call_state'];
        }

        // Explicit no-call
        if ($callState === VoiceSession::CALL_STATE_NO_CALL) {
            if (!$isFresh) {
                return ['confirm', 'other_tab_stale_no_call'];
            }
            return match ($agentStatus) {
                'offline', 'paused' => ['silent', "other_tab_{$agentStatus}_no_call"],
                'ready'             => ['confirm', 'other_tab_ready_no_call'],
                default             => ['confirm', 'other_tab_unknown_agent_status'],
            };
        }

        // Unknown (null in DB — pre-migration session, or browser never reported)
        // Conservative: confirm, never silent or blocked
        return ['confirm', 'other_tab_unknown_call_state'];
    }

    private function isCallStateFresh(VoiceSession $session, int $freshSeconds): bool
    {
        if (!$session->call_state_updated_at) {
            return false;
        }
        return $session->call_state_updated_at->isAfter(now()->subSeconds($freshSeconds));
    }

    /**
     * Determine the operational status of the agent who owns $session.
     * Source: AgentSession table (single record per user, created on first Ready click).
     *   - No AgentSession row → agent is Offline (never went Ready in this session).
     *   - status='ready'  → Ready
     *   - status='paused' → Paused
     */
    private function agentStatus(VoiceSession $session): string
    {
        $agentSession = AgentSession::withoutGlobalScopes()
            ->where('user_id', $session->user_id)
            ->where('tenant_id', $session->tenant_id)
            ->first();

        if (!$agentSession) {
            return 'offline';
        }

        return $agentSession->isReady() ? 'ready' : 'paused';
    }
}
