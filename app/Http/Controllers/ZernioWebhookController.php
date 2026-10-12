<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\NewMessageReceived;
use App\Models\AgentSession;
use App\Models\Conversation;
use App\Models\CrmFile;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Models\SocialAccount;
use App\Services\Automation\ConversationAutomationForwardingService;
use App\Services\Storage\CrmFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Webhook global de Zernio — mensajes entrantes de Facebook Messenger e Instagram DM.
 * Un solo endpoint para todos los tenants: el routing es por account.id → social_accounts.
 * Espejo del patrón de WhatsappWebhookController.
 */
class ZernioWebhookController extends Controller
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac',
        'video/mp4', 'video/3gpp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    public function __construct(
        private readonly CrmFileService $fileService,
        private readonly ConversationAutomationForwardingService $automationForwardingService,
    ) {}

    /**
     * POST — Zernio envía eventos aquí. SIEMPRE 200 rápido.
     */
    public function receive(Request $request)
    {
        $key = 'zernio_webhook:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 500)) {
            Log::warning('Zernio webhook: rate limit exceeded', ['ip' => $request->ip()]);
            return response('OK', 200);
        }
        RateLimiter::hit($key, 60);

        $rawBody = $request->getContent();

        // Verificación HMAC-SHA256 — header X-Zernio-Signature
        $secret = (string) config('services.zernio.webhook_secret');

        if ($secret !== '') {
            $signature = (string) $request->header('X-Zernio-Signature', '');
            $expected  = hash_hmac('sha256', $rawBody, $secret);

            // hash_equals: comparación en tiempo constante. Aceptar con y sin prefijo sha256=
            if (!$signature ||
                (!hash_equals($expected, $signature) && !hash_equals('sha256=' . $expected, $signature))) {
                Log::warning('Zernio webhook: invalid signature');
                // 200 intencional: silent rejection (mismo estándar que el webhook de WA)
                return response('OK', 200);
            }
        }

        try {
            $payload = json_decode($rawBody, true) ?? [];
            $event   = $payload['event'] ?? null;

            match ($event) {
                'message.received'     => $this->handleMessageReceived($payload),
                'account.disconnected' => $this->handleAccountDisconnected($payload),
                'webhook.test'         => Log::info('Zernio webhook: test event received OK'),
                default                => Log::info("Zernio webhook: ignoring event [{$event}]"),
            };

        } catch (\Throwable $e) {
            Log::error('Zernio webhook: unexpected error — ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('OK', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function handleMessageReceived(array $payload): void
    {
        $msg  = $payload['message'] ?? [];
        $conv = $payload['conversation'] ?? [];
        $acc  = $payload['account'] ?? [];

        // ⚠️ El payload REAL usa camelCase (conversationId, sentAt, participantName...)
        // aunque los modelos del SDK documentan snake_case — aceptar ambos.
        $zernioAccountId = $acc['id'] ?? null;
        $zernioConvId    = $conv['id'] ?? ($msg['conversationId'] ?? $msg['conversation_id'] ?? null);
        $zernioMsgId     = $msg['id'] ?? null;

        if (!$zernioAccountId || !$zernioConvId || !$zernioMsgId) {
            Log::warning('Zernio webhook: incomplete message payload', ['payload' => $payload]);
            return;
        }

        // Solo entrantes — los salientes del agente ya se guardan al enviar.
        // El payload real usa 'incoming'/'outgoing'; el SDK documenta 'inbound'/'outbound'.
        $direction = $msg['direction'] ?? 'incoming';
        if (!in_array($direction, ['incoming', 'inbound'], true)) {
            Log::info("Zernio webhook: skipping non-incoming message {$zernioMsgId} [{$direction}]");
            return;
        }

        $account = SocialAccount::withoutGlobalScope(TenantScope::class)
            ->with('omniQueue')
            ->where('zernio_account_id', $zernioAccountId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('Zernio webhook: no social account for zernio_account_id', [
                'zernio_account_id' => $zernioAccountId,
            ]);
            return;
        }

        if (Message::where('message_id', $zernioMsgId)->exists()) {
            Log::info("Zernio webhook: skipping duplicate message {$zernioMsgId}");
            return;
        }

        $channel         = $account->platform; // facebook | instagram
        $participantName = $conv['participantName'] ?? $conv['participant_name'] ?? ($msg['sender']['name'] ?? ucfirst($channel) . ' User');
        $participantId   = $conv['participantId'] ?? $conv['participant_id'] ?? null;
        // sentAt llega en UTC (ISO 8601 con Z) — convertir a la TZ de la app
        $sentAtRaw       = $msg['sentAt'] ?? $msg['sent_at'] ?? null;
        $sentAt          = $sentAtRaw
            ? \Carbon\Carbon::parse($sentAtRaw)->setTimezone(config('app.timezone'))
            : now();
        $attachments     = $msg['attachments'] ?? [];
        $bodyText        = $this->extractBody($msg, $attachments);

        $conversation    = null;
        $previousAgentId = null;

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $account->tenant_id)
            ->where('channel', $channel)
            ->where('external_id', $zernioConvId)
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->first();

        if ($conversation) {
            $previousAgentId = $conversation->assigned_to;
            $updates = ['wait_since' => now()];
            if ($participantName && !$conversation->from_name) {
                $updates['from_name'] = $participantName;
            }
            Conversation::withoutTimestamps(fn() => $conversation->update($updates));
        } else {
            // A brand-new conversation starts out AI-controlled only if automation
            // is actually enabled for this tenant+channel — never reactivated
            // automatically afterwards (e.g. after a human handoff).
            $startsAiControlled = $this->automationForwardingService->isEnabledForInbound($account->tenant_id, $channel);

            $conversation = Conversation::create([
                'tenant_id'         => $account->tenant_id,
                'channel'           => $channel,
                'social_account_id' => $account->id,
                'omni_queue_id'     => $account->omni_queue_id,
                'external_id'       => $zernioConvId,
                'from_email'        => $participantId ?? $zernioConvId,
                'from_name'         => $participantName,
                'subject'           => $participantName,
                'status'            => 'pending',
                'wait_since'        => $sentAt,
                'ai_bot_active'     => $startsAiControlled,
                'direction'         => 'inbound',
            ]);

            Log::info("Zernio webhook: new {$channel} conversation #{$conversation->id} from {$participantName}" . ($startsAiControlled ? ' [AI-controlled]' : ''));
        }

        if ($previousAgentId) {
            $agentReady = AgentSession::where('user_id', $previousAgentId)
                ->where('status', 'ready')
                ->exists();

            if (!$agentReady) {
                $conversation->update(['status' => 'pending', 'wait_since' => now()]);
                Log::info("Zernio webhook: conversation #{$conversation->id} — agent #{$previousAgentId} not ready, held pending");
            }
        }

        $firstAttachmentType = $attachments[0]['type'] ?? null;

        $message = Message::create([
            'tenant_id'       => $account->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'from_email'      => $participantId ?? $zernioConvId,
            'from_name'       => $participantName,
            'to_email'        => $account->username ?? $account->display_name,
            'body_text'       => $bodyText,
            'body_html'       => null,
            'media_type'      => $firstAttachmentType,
            'message_id'      => $zernioMsgId,
            'sent_at'         => $sentAt,
        ]);

        // Descargar attachments y subir a R2
        $mediaUrl = null;
        foreach ($attachments as $attachment) {
            $crmFile = $this->uploadAttachment($attachment, $message, $channel);
            if ($crmFile && !$mediaUrl) {
                $mediaUrl = $this->generateMediaUrl($crmFile);
            }
        }

        // Notificar en tiempo real via Pusher
        if ($conversation->assigned_to && in_array($conversation->status, ['active', 'pending'])) {
            $agentReady = AgentSession::where('user_id', $conversation->assigned_to)
                ->where('status', 'ready')
                ->exists();

            if ($agentReady) {
                event(new NewMessageReceived($message, $conversation, $firstAttachmentType, $mediaUrl));
            }
        }

        Log::info("Zernio webhook: saved {$channel} message {$zernioMsgId} conv #{$conversation->id}");

        // Optional per-tenant, per-channel forwarding to an external AI/automation
        // webhook. No-op when disabled/unconfigured for this tenant+channel.
        $aiBotActive = Conversation::withoutGlobalScope(TenantScope::class)
            ->whereKey($conversation->id)
            ->value('ai_bot_active');

        if ((bool) $aiBotActive) {
            $this->automationForwardingService->forwardInboundMessage($account->tenant_id, $channel, [
                'event'                => "{$channel}.inbound_message",
                'tenant_id'            => $account->tenant_id,
                'tenant_slug'          => $account->tenant?->slug,
                'channel'              => $channel,
                'conversation_id'      => $conversation->id,
                'message_id'           => $message->id,
                'external_message_id'  => $zernioMsgId,
                'from'                 => $participantId ?? $zernioConvId,
                'to'                   => $account->username ?? $account->display_name,
                'contact_name'         => $participantName,
                'message_type'         => $firstAttachmentType ?? 'text',
                'text'                 => $bodyText,
                'received_at'          => $sentAt->toIso8601String(),
                'source'               => 'crm.xedik.cloud',
                'raw'                  => $msg,
            ]);
        }
    }

    private function handleAccountDisconnected(array $payload): void
    {
        $zernioAccountId = $payload['account']['id'] ?? null;

        if (!$zernioAccountId) {
            return;
        }

        $account = SocialAccount::withoutGlobalScope(TenantScope::class)
            ->where('zernio_account_id', $zernioAccountId)
            ->first();

        if ($account) {
            $account->update(['is_active' => false]);
            Log::warning("Zernio webhook: account {$account->display_name} ({$account->platform}) DISCONNECTED — marked inactive. Tenant must reconnect.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function uploadAttachment(array $attachment, Message $message, string $channel): ?CrmFile
    {
        $url  = $attachment['url'] ?? null;
        $type = $attachment['type'] ?? 'file';

        if (!$url) {
            return null;
        }

        $tmpPath = null;

        try {
            $resp = Http::timeout(60)->get($url);

            if (!$resp->successful()) {
                Log::warning("Zernio media: download failed [{$type}]", ['status' => $resp->status()]);
                return null;
            }

            $mimeType = explode(';', $resp->header('Content-Type') ?: 'application/octet-stream')[0];
            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                Log::warning("Zernio media: MIME type bloqueado [{$mimeType}] msg #{$message->id} — archivo descartado.");
                return null;
            }

            $ext     = $this->guessExtension($mimeType);
            $tmpPath = tempnam(sys_get_temp_dir(), 'zn_') . '.' . $ext;
            file_put_contents($tmpPath, $resp->body());

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tmpPath,
                $type . '_' . $message->id . '.' . $ext,
                $mimeType,
                null,
                true
            );

            $crmFile = $this->fileService->uploadFile($message->tenant_id, $uploadedFile, [
                'channel'         => $channel,
                'category'        => $this->typeToCategory($type),
                'source'          => $channel,
                'direction'       => 'inbound',
                'conversation_id' => $message->conversation_id,
                'message_id'      => $message->id,
            ]);

            Log::info("Zernio media: uploaded [{$type}] as crm_file #{$crmFile->id} for msg #{$message->id}");
            return $crmFile;

        } catch (\Throwable $e) {
            Log::error("Zernio media: upload failed — " . $e->getMessage());
            return null;
        } finally {
            if ($tmpPath) {
                @unlink($tmpPath);
            }
        }
    }

    private function generateMediaUrl(CrmFile $crmFile): ?string
    {
        try {
            return $this->fileService->generateTemporaryUrl($crmFile->id, null, ttlMinutes: 60);
        } catch (\Throwable $e) {
            Log::warning("Zernio media: temp URL generation failed for file #{$crmFile->id} — " . $e->getMessage());
            return null;
        }
    }

    private function typeToCategory(string $type): string
    {
        return match($type) {
            'image'         => 'images',
            'audio'         => 'audio',
            'video'         => 'video',
            'file', 'document' => 'documents',
            'sticker'       => 'stickers',
            default         => 'other',
        };
    }

    private function guessExtension(string $mimeType): string
    {
        return match($mimeType) {
            'image/jpeg'                => 'jpg',
            'image/png'                 => 'png',
            'image/webp'                => 'webp',
            'image/gif'                 => 'gif',
            'audio/ogg'                 => 'ogg',
            'audio/mpeg'                => 'mp3',
            'audio/mp4'                 => 'm4a',
            'audio/aac'                 => 'aac',
            'video/mp4'                 => 'mp4',
            'video/3gpp'                => '3gp',
            'application/pdf'           => 'pdf',
            'application/msword'        => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel'  => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default                     => 'bin',
        };
    }

    /**
     * Texto para body_text — preview en lista de conversaciones y Pusher.
     */
    private function extractBody(array $msg, array $attachments): string
    {
        $text = trim((string) ($msg['text'] ?? ''));

        if ($text !== '') {
            return $text;
        }

        $type = $attachments[0]['type'] ?? null;

        return match($type) {
            'image'   => '[📷 Image]',
            'audio'   => '[🎵 Audio]',
            'video'   => '[🎥 Video]',
            'file'    => '[📄 File]',
            'sticker' => '[Sticker]',
            default   => '[Message]',
        };
    }
}
