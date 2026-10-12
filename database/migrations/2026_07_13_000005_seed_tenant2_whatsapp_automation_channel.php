<?php

declare(strict_types=1);

use App\Models\ConversationAutomationChannel;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off production data migration: creates the initial WhatsApp automation
 * config row for tenant 2 / slug "itsaar" (ITSAAR production), mirroring the
 * n8n webhook already live in tenants.settings->whatsapp_ai_forwarding.
 *
 * Safety:
 *  - Requires BOTH id=2 AND slug='itsaar' to match — a no-op on any other
 *    environment (local/staging seeds tenant_id differently).
 *  - Never overwrites an existing conversation_automation_channels row —
 *    firstOrCreate only, no updateOrCreate.
 *  - Does NOT touch tenants.settings — the legacy fallback stays intact.
 *  - down() only deletes rows that still match exactly what this migration
 *    inserted, so it never clobbers config an admin edited afterwards via
 *    the settings screen.
 */
return new class extends Migration
{
    private const TENANT_ID = 2;
    private const TENANT_SLUG = 'itsaar';
    private const WHATSAPP_URL = 'http://automate.itsaar.com:5678/webhook/saarbot-whatsapp';
    private const DISABLED_CHANNELS = ['facebook', 'instagram', 'chat'];

    public function up(): void
    {
        $tenant = Tenant::where('id', self::TENANT_ID)
            ->where('slug', self::TENANT_SLUG)
            ->first();

        if (!$tenant) {
            // Not the ITSAAR production environment — do nothing.
            return;
        }

        ConversationAutomationChannel::firstOrCreate(
            ['tenant_id' => self::TENANT_ID, 'channel' => 'whatsapp'],
            [
                'enabled'     => true,
                'webhook_url' => self::WHATSAPP_URL,
                'events'      => ['inbound_message'],
                'auth_type'   => 'none',
                'api_key'     => null,
            ]
        );

        foreach (self::DISABLED_CHANNELS as $channel) {
            ConversationAutomationChannel::firstOrCreate(
                ['tenant_id' => self::TENANT_ID, 'channel' => $channel],
                [
                    'enabled'     => false,
                    'webhook_url' => null,
                    'events'      => ['inbound_message'],
                    'auth_type'   => 'none',
                ]
            );
        }
    }

    public function down(): void
    {
        $tenant = Tenant::where('id', self::TENANT_ID)
            ->where('slug', self::TENANT_SLUG)
            ->first();

        if (!$tenant) {
            return;
        }

        // Only remove the WhatsApp row if it still matches exactly what this
        // migration inserted — if an admin has since changed it via the
        // settings screen, leave it alone.
        ConversationAutomationChannel::where('tenant_id', self::TENANT_ID)
            ->where('channel', 'whatsapp')
            ->where('enabled', true)
            ->where('webhook_url', self::WHATSAPP_URL)
            ->where('auth_type', 'none')
            ->whereNull('api_key')
            ->get()
            ->each(function (ConversationAutomationChannel $row) {
                $events = $row->events ?? [];
                if ($events === ['inbound_message']) {
                    $row->delete();
                }
            });

        // Only remove the disabled placeholder rows if they were never touched.
        ConversationAutomationChannel::where('tenant_id', self::TENANT_ID)
            ->whereIn('channel', self::DISABLED_CHANNELS)
            ->where('enabled', false)
            ->whereNull('webhook_url')
            ->where('auth_type', 'none')
            ->whereNull('api_key')
            ->delete();
    }
};
