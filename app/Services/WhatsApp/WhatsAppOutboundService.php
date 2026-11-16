<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Http;

/**
 * Single point of contact for sending a WhatsApp text reply into an existing
 * conversation. Extracted from Api\WhatsappController::sendBotReply so both
 * the legacy /api/whatsapp/bot-reply endpoint and the generic
 * /api/automation/conversations/{conversation}/reply endpoint share the
 * exact same Graph API call — no duplicated channel-sending logic.
 */
class WhatsAppOutboundService
{
    /**
     * @return array{ok: bool, message?: Message, wamid?: ?string, error?: string, status?: int}
     */
    public function sendText(Conversation $conversation, WhatsappAccount $account, string $text, ?string $fromName = null, ?int $sentBy = null): array
    {
        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');

        $isConfirmedOrder = str_contains($text, '[PEDIDO_CONFIRMADO]');
        if ($isConfirmedOrder) {
            $text = trim(str_replace('[PEDIDO_CONFIRMADO]', '', $text));
        }

        try {
            $response = Http::withToken($account->access_token)
                ->timeout(10)
                ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $conversation->external_id,
                    'type'              => 'text',
                    'text'              => ['body' => $text],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'WhatsApp send failed: ' . $e->getMessage(), 'status' => 502];
        }

        if ($response->failed()) {
            return ['ok' => false, 'error' => 'WhatsApp API error: ' . $response->body(), 'status' => 502];
        }

        $wamid = $response->json('messages.0.id');

        $message = Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->phone_number,
            'from_name'       => $fromName ?? 'SAARBot',
            'to_email'        => $conversation->external_id,
            'body_text'       => $text,
            'body_html'       => null,
            'message_id'      => $wamid,
            'sent_by'         => $sentBy,
            'sent_at'         => now(),
        ]);

        // Si el bot confirmó un pedido ([PEDIDO_CONFIRMADO]), crear comanda en KDS y notificar al WhatsApp de Cocina (con soporte Plantilla 24h)
        if ($isConfirmedOrder) {
            try {
                app(\App\Services\Restaurant\RestaurantOrderService::class)->createOrderAndNotify([
                    'tenant_id'       => $conversation->tenant_id,
                    'channel'         => 'whatsapp',
                    'customer_name'   => $conversation->from_name ?: 'Cliente WhatsApp',
                    'customer_phone'  => $conversation->external_id,
                    'raw_text'        => $text,
                    'conversation_id' => $conversation->id,
                    'notify_customer' => false, // El cliente ya recibió el ticket en la respuesta de la conversación activa
                    'notify_kitchen'  => true,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[WhatsAppOutboundService] Kitchen notification failed: ' . $e->getMessage());
            }
        }

        return ['ok' => true, 'message' => $message, 'wamid' => $wamid];
    }
}
