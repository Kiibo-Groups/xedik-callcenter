<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Models\WebchatSession;
use App\Services\Sms\Exceptions\SmsOptOutException;
use App\Services\Sms\Exceptions\SmsSendException;
use App\Services\Sms\SmsService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Services\ZernioService;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

/**
 * Routes an outbound automation reply to the right channel-specific sender
 * based on conversation.channel — n8n/automation callers never choose the
 * provider themselves, they only ever pass a conversation_id + message.
 * Reuses the existing WhatsApp/Zernio outbound services, no duplicated
 * channel-sending logic.
 */
class AutomationReplyRouterService
{
    public function __construct(
        private readonly WhatsAppOutboundService $whatsAppOutbound,
        private readonly ZernioService $zernio,
        private readonly SmsService $sms,
    ) {}

    /**
     * @return array{ok: bool, message?: \App\Models\Message, external_id?: ?string, error?: string, status?: int}
     */
    public function send(Conversation $conversation, string $text, string $fromName = 'Bot', ?int $sentBy = null): array
    {
        return match ($conversation->channel) {
            'whatsapp' => $this->sendWhatsapp($conversation, $text, $fromName, $sentBy),
            'chat' => $this->sendChat($conversation, $text, $fromName, $sentBy),
            'facebook', 'instagram' => $this->sendSocial($conversation, $text, $fromName, $sentBy),
            'sms' => $this->sendSms($conversation, $text),
            default => [
                'ok'     => false,
                'error'  => "Unsupported channel '{$conversation->channel}' for automation reply.",
                'status' => 422,
            ],
        };
    }

    private function sendWhatsapp(Conversation $conversation, string $text, string $fromName, ?int $sentBy): array
    {
        $account = $conversation->whatsappAccount;
        if (!$account || !$account->is_active) {
            return ['ok' => false, 'error' => 'No active WhatsApp account linked to this conversation.', 'status' => 422];
        }

        $result = $this->whatsAppOutbound->sendText($conversation, $account, $text, $fromName, $sentBy);
        if (!$result['ok']) {
            return $result;
        }

        return ['ok' => true, 'message' => $result['message'], 'external_id' => $result['wamid'] ?? null];
    }

    private function sendSocial(Conversation $conversation, string $text, string $fromName, ?int $sentBy): array
    {
        $account = $conversation->socialAccount;
        if (!$account || !$account->is_active) {
            return ['ok' => false, 'error' => 'No active social account linked to this conversation.', 'status' => 422];
        }

        try {
            $result = $this->zernio->sendMessage($conversation->external_id, $account->zernio_account_id, $text);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not send the message. The messaging window may have expired.', 'status' => 502];
        }

        $message = \App\Models\Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->username ?? $account->display_name,
            'from_name'       => $fromName,
            'to_email'        => $conversation->from_email,
            'body_text'       => $text,
            'body_html'       => null,
            'message_id'      => $result['message_id'] ?? null,
            'sent_by'         => $sentBy,
            'sent_at'         => now(),
        ]);

        return ['ok' => true, 'message' => $message, 'external_id' => $result['message_id'] ?? null];
    }

    private function sendSms(Conversation $conversation, string $text): array
    {
        try {
            $message = $this->sms->sendReply($conversation, $text, null);
        } catch (SmsOptOutException $e) {
            return ['ok' => false, 'error' => 'This number has opted out of SMS (STOP).', 'status' => 422];
        } catch (SmsSendException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 502];
        }

        return ['ok' => true, 'message' => $message, 'external_id' => $message->provider_message_id];
    }

    private function sendChat(Conversation $conversation, string $text, string $fromName, ?int $sentBy): array
    {
        $message = Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_name'       => $fromName,
            'from_email'      => 'bot@internal',
            'to_email'        => $conversation->from_email,
            'body_text'       => $text,
            'sent_by'         => $sentBy,
            'sent_at'         => now(),
        ]);

        $session = WebchatSession::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($session) {
            try {
                $pusher = new Pusher(
                    config('broadcasting.connections.pusher.key'),
                    config('broadcasting.connections.pusher.secret'),
                    config('broadcasting.connections.pusher.app_id'),
                    ['cluster' => config('broadcasting.connections.pusher.options.cluster')]
                );
                $pusher->trigger('webchat.' . $session->session_token, 'agent-message', [
                    'id'           => $message->id,
                    'body_text'    => $message->body_text,
                    'from_name'    => $fromName,
                    'from_initial' => mb_substr($fromName, 0, 1),
                    'sent_at'      => $message->sent_at->toISOString(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('automation sendChat Pusher error: ' . $e->getMessage());
            }
        }

        return ['ok' => true, 'message' => $message, 'external_id' => null];
    }
}
