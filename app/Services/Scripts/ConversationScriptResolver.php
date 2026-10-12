<?php

declare(strict_types=1);

namespace App\Services\Scripts;

use App\Models\Conversation;
use App\Models\Script;

/**
 * Resolves the effective Script for a given Conversation.
 *
 * Priority order:
 *   1. Queue-specific script  (omni_queue.script_id — active, same campaign, matching direction)
 *   2. Queue Campaign default (most relevant active script of queue's campaign)
 *   3. Lead Campaign default  (most relevant active script of lead's campaign)
 *   4. null                   (no script configured)
 *
 * All lookups are tenant-scoped and avoid N+1 via eager-loaded relations.
 * This service is read-only and never mutates any model.
 */
class ConversationScriptResolver
{
    /** Allowed direction values */
    private const DIRECTIONS = ['inbound', 'outbound', 'both'];

    /**
     * Resolve the effective script for the conversation.
     *
     * Pass the conversation with the following relations already loaded
     * (the caller is responsible for eager loading to avoid N+1):
     *   - omniQueue.script
     *   - omniQueue.campaign
     *   - lead.campaign
     *
     * @return array{script: Script|null, script_source: string}
     */
    public function resolve(Conversation $conversation): array
    {
        $tenantId  = $conversation->tenant_id;
        $direction = $this->effectiveDirection($conversation);

        // ── 0. Explicitly selected script ────────────────────────────────────
        // Set on the conversation when the agent picks a script from Make a Call.
        // Takes priority over all automatic resolution.
        if ($conversation->script_id) {
            $script = $conversation->relationLoaded('script')
                ? $conversation->script
                : Script::withoutGlobalScopes()
                    ->where('id', $conversation->script_id)
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->first();

            if ($script) {
                return ['script' => $script, 'script_source' => 'selected'];
            }
            // Script was deactivated/deleted — fall through to automatic resolution
        }

        // ── 1. Queue-specific script ──────────────────────────────────────────
        $queue = $conversation->relationLoaded('omniQueue')
            ? $conversation->omniQueue
            : $conversation->omniQueue()->with('script')->first();

        if ($queue && $queue->script_id) {
            $script = $queue->relationLoaded('script') ? $queue->script : $queue->script()->first();

            if ($script && $this->scriptIsValid($script, $tenantId, $queue->campaign_id, $direction)) {
                return ['script' => $script, 'script_source' => 'queue'];
            }
            // Invalid queue script → fall through to campaign default
        }

        // ── 2. Queue Campaign default ─────────────────────────────────────────
        if ($queue && $queue->campaign_id) {
            $script = $this->findDefaultScript(
                $queue->campaign_id,
                $tenantId,
                $direction
            );
            if ($script) {
                return ['script' => $script, 'script_source' => 'queue_campaign_default'];
            }
        }

        // ── 3. Lead Campaign default ──────────────────────────────────────────
        $lead     = $conversation->relationLoaded('lead') ? $conversation->lead : null;
        $campaign = $lead?->relationLoaded('campaign') ? $lead->campaign : $lead?->campaign;

        if ($campaign) {
            $script = $this->findDefaultScript($campaign->id, $tenantId, $direction);
            if ($script) {
                return ['script' => $script, 'script_source' => 'lead_campaign_default'];
            }
        }

        return ['script' => null, 'script_source' => 'none'];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns the effective direction from the conversation's `direction` column.
     * Falls back to 'both' if the field is null or unrecognised.
     *
     * Source: conversations.direction (populated at creation / first message).
     */
    private function effectiveDirection(Conversation $conversation): string
    {
        $dir = $conversation->direction;
        return in_array($dir, ['inbound', 'outbound'], true) ? $dir : 'both';
    }

    /**
     * Verify that a script can be used for the given conversation context.
     *
     * Checks:
     *  - same tenant
     *  - is_active
     *  - belongs to the expected campaign (or campaign-agnostic future)
     *  - direction matches exactly or is 'both'
     */
    private function scriptIsValid(
        Script $script,
        int $tenantId,
        ?int $campaignId,
        string $effectiveDirection
    ): bool {
        // Cast to int for safe comparison (SQLite returns strings for integer columns)
        if ((int) $script->tenant_id !== $tenantId) {
            return false;
        }
        if (!$script->is_active) {
            return false;
        }
        if ($campaignId && (int) $script->campaign_id !== $campaignId) {
            return false;
        }
        // Direction must match exactly or the script must cover 'both'
        return in_array($script->direction, [$effectiveDirection, 'both'], true);
    }

    /**
     * Find the best active default script for a campaign.
     *
     * Selection order:
     *   1. Exact direction match (is_default DESC → sort_order ASC → id ASC)
     *   2. Direction 'both'     (is_default DESC → sort_order ASC → id ASC)
     */
    private function findDefaultScript(
        int $campaignId,
        int $tenantId,
        string $effectiveDirection
    ): ?Script {
        $base = Script::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id');

        // Try exact direction first
        if ($effectiveDirection !== 'both') {
            $exact = (clone $base)->where('direction', $effectiveDirection)->first();
            if ($exact) {
                return $exact;
            }
        }

        // Fall back to 'both'
        return (clone $base)->where('direction', 'both')->first();
    }
}
