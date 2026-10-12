<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\ConversationAutomationChannel;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards inbound conversation messages to a per-tenant, per-channel external
 * automation webhook (e.g. an n8n/AI pipeline). Resolution order:
 *
 *   1. conversation_automation_channels row for tenant + channel
 *   2. tenants.settings->whatsapp_ai_forwarding fallback — WhatsApp only,
 *      temporary, kept for backward compatibility with the pre-existing
 *      integration until it is migrated into the new table everywhere.
 *   3. disabled (no-op)
 *
 * Never allowed to affect normal inbound processing: all failures are
 * caught and logged, never thrown, and the inbound message is never rolled
 * back regardless of forwarding outcome.
 */
class ConversationAutomationForwardingService
{
    private const LOG_PREFIX = '[ConversationAutomationForwarding]';

    /**
     * Whether inbound-message automation is enabled for this tenant+channel.
     * Used by inbound webhook controllers to decide whether a brand-new
     * conversation should start out AI-controlled (ai_bot_active = true).
     */
    public function isEnabledForInbound(int $tenantId, string $channel): bool
    {
        return $this->resolveConfig($tenantId, $channel) !== null;
    }

    public function forwardInboundMessage(int $tenantId, string $channel, array $payload): void
    {
        $config = $this->resolveConfig($tenantId, $channel);
        if ($config === null) {
            return;
        }

        $webhookUrl = $config['webhook_url'] ?? null;
        if (!is_string($webhookUrl) || $webhookUrl === '') {
            Log::warning(self::LOG_PREFIX . ' enabled but webhook_url is missing', [
                'tenant_id' => $tenantId,
                'channel'   => $channel,
            ]);
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'XEDIK-Conversation-Automation/1.0',
        ];

        $authType = $config['auth_type'] ?? 'none';
        $apiKey   = $config['api_key'] ?? null;
        if ($authType === 'api_key' && !empty($apiKey)) {
            $headers['X-API-Key'] = $apiKey;
        }

        $timeoutSeconds = max(1, (int) round(($config['timeout_ms'] ?? 5000) / 1000));

        $logContext = [
            'tenant_id'       => $tenantId,
            'channel'         => $channel,
            'webhook_url'     => $webhookUrl,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'message_id'      => $payload['message_id'] ?? null,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(3, $timeoutSeconds))
                ->post($webhookUrl, $payload);

            Log::info(self::LOG_PREFIX . ' forwarded inbound message', $logContext + [
                'status' => $response->status(),
            ]);
        } catch (Throwable $e) {
            Log::warning(self::LOG_PREFIX . ' forward failed', $logContext + [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{webhook_url:?string,auth_type:string,api_key:?string,timeout_ms:int}|null
     */
    private function resolveConfig(int $tenantId, string $channel): ?array
    {
        $row = ConversationAutomationChannel::where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->first();

        if ($row) {
            if (!$row->enabled) {
                return null;
            }
            $events = is_array($row->events) ? $row->events : [];
            if (!in_array('inbound_message', $events, true)) {
                return null;
            }

            return [
                'webhook_url' => $row->webhook_url,
                'auth_type'   => $row->auth_type,
                'api_key'     => $row->api_key,
                'timeout_ms'  => $row->timeout_ms,
            ];
        }

        // Temporary fallback — WhatsApp only. Remove once every tenant has a
        // conversation_automation_channels row for 'whatsapp'.
        if ($channel !== 'whatsapp') {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return null;
        }

        $legacy = $tenant->settings['whatsapp_ai_forwarding'] ?? null;
        if (!is_array($legacy) || ($legacy['enabled'] ?? false) !== true) {
            return null;
        }

        $events = $legacy['events'] ?? [];
        if (!is_array($events) || !in_array('inbound_message', $events, true)) {
            return null;
        }

        return [
            'webhook_url' => $legacy['webhook_url'] ?? null,
            'auth_type'   => $legacy['auth_type'] ?? 'none',
            'api_key'     => $legacy['api_key'] ?? null,
            'timeout_ms'  => 5000,
        ];
    }
}
