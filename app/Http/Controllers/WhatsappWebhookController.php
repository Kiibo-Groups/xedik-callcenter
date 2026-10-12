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
use App\Models\WhatsappAccount;
use App\Services\Automation\ConversationAutomationForwardingService;
use App\Services\Storage\CrmFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class WhatsappWebhookController extends Controller
{
    private const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

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
     * GET — Meta verifica el webhook al configurarlo en el dashboard.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $account = WhatsappAccount::withoutGlobalScope(TenantScope::class)
            ->where('verify_token', $token)
            ->where('is_active', true)
            ->first();

        if ($mode === 'subscribe' && $account) {
            Log::info("WhatsApp webhook verified: {$account->display_name}");
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', ['mode' => $mode, 'token' => $token]);
        return response('Forbidden', 403);
    }

    /**
     * POST — Meta envía mensajes entrantes aquí.
     * SIEMPRE devolver 200 rápido o Meta reintenta sin parar.
     */
    public function receive(Request $request)
    {
        $key = 'wa_webhook:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 500)) {
            Log::warning('WhatsApp webhook: rate limit exceeded', ['ip' => $request->ip()]);
            return response('OK', 200);
        }
        RateLimiter::hit($key, 60);

        $rawBody = $request->getContent();

        try {
            $payload = json_decode($rawBody, true) ?? [];
            $changes = $payload['entry'][0]['changes'][0]['value'] ?? [];

            if (empty($changes['messages'])) {
                return response('OK', 200);
            }

            $phoneNumberId = $changes['metadata']['phone_number_id'] ?? null;

            if (!$phoneNumberId) {
                Log::warning('WhatsApp webhook: missing phone_number_id');
                return response('OK', 200);
            }

            $account = WhatsappAccount::withoutGlobalScope(TenantScope::class)
                ->with('omniQueue')
                ->where('phone_number_id', $phoneNumberId)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                Log::warning('WhatsApp webhook: no account for phone_number_id', [
                    'phone_number_id' => $phoneNumberId,
                ]);
                return response('OK', 200);
            }

            if (!$account->app_secret) {
                Log::critical('WhatsApp webhook: app_secret not configured — payload rejected', [
                    'account'        => $account->display_name,
                    'phone_number_id' => $phoneNumberId,
                ]);
                // Intencionalmente 200: devolver 4xx haría que Meta reintente el payload
                // y eventualmente deshabilite el webhook. Silent rejection es el estándar
                // de la industria (GitHub, Stripe, Twilio, Meta docs).
                return response('OK', 200);
            }

            // hash_equals: comparación en tiempo constante — previene timing attacks.
            // No usar $signature !== $expected (vulnerable a timing attack).
            $signature = $request->header('X-Hub-Signature-256');
            $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $account->app_secret);

            if (!$signature || !hash_equals($expected, $signature)) {
                Log::warning('WhatsApp webhook: invalid signature', [
                    'account' => $account->display_name,
                ]);
                // Intencionalmente 200: ver comentario arriba sobre silent rejection.
                return response('OK', 200);
            }

            $messages = $changes['messages'] ?? [];
            $contacts = $changes['contacts'] ?? [];

            foreach ($messages as $msgData) {
                // Debug temporal: capturar estructura completa para investigar
                // mensajes de Click-to-WhatsApp (referral con source_url/preview).
                // Quitar una vez capturado un payload real con `referral`.
                if (isset($msgData['referral']) || isset($msgData['context'])) {
                    Log::info('WA-DEBUG: mensaje con referral/context', ['msgData' => $msgData]);
                }

                $this->processMessage($msgData, $contacts, $account);
            }

        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook: unexpected error — ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('OK', 200);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function processMessage(array $msgData, array $contacts, WhatsappAccount $account): void
    {
        $wamid  = $msgData['id'];
        $from   = $msgData['from'];
        $type   = $msgData['type'];
        $sentAt = now()->setTimestamp((int) ($msgData['timestamp'] ?? now()->timestamp));

        $contactName = collect($contacts)
            ->firstWhere('wa_id', $from)['profile']['name'] ?? null;

        if (Message::where('message_id', $wamid)->exists()) {
            Log::info("WhatsApp webhook: skipping duplicate wamid {$wamid}");
            return;
        }

        $isMedia      = in_array($type, self::MEDIA_TYPES, true);
        $bodyText     = $this->extractBody($msgData, $type);
        $displayPhone = \App\Helpers\PhoneHelper::formatDisplay($from);

        $lead = Lead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $account->tenant_id)
            ->where(function ($q) use ($from, $displayPhone) {
                $q->where('phone', $from)->orWhere('phone', $displayPhone);
            })
            ->first();

        $conversation    = null;
        $previousAgentId = null;

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $account->tenant_id)
            ->where('channel', 'whatsapp')
            ->where('external_id', $from)
            ->whereIn('status', ['pending', 'active'])
            ->latest()
            ->first();

        if ($conversation) {
            $previousAgentId = $conversation->assigned_to;
            $updates = ['wait_since' => now()];
            if ($contactName && !$conversation->from_name) {
                $updates['from_name'] = $contactName;
            }
            Conversation::withoutTimestamps(fn() => $conversation->update($updates));
        } else {
            // A brand-new conversation starts out AI-controlled only if automation
            // is actually enabled for this tenant+channel — never reactivated
            // automatically afterwards (e.g. after a human handoff).
            $startsAiControlled = $this->automationForwardingService->isEnabledForInbound($account->tenant_id, 'whatsapp');

            $conversation = Conversation::create([
                'tenant_id'           => $account->tenant_id,
                'channel'             => 'whatsapp',
                'whatsapp_account_id' => $account->id,
                'omni_queue_id'       => $account->omni_queue_id,
                'lead_id'             => $lead?->id,
                'external_id'         => $from,
                'from_email'          => $displayPhone,
                'from_name'           => $contactName ?? $displayPhone,
                'subject'             => $displayPhone,
                'status'              => 'pending',
                'wait_since'          => $sentAt,
                'ai_bot_active'       => $startsAiControlled,
                'direction'           => 'inbound',
            ]);

            Log::info("WhatsApp webhook: new conversation #{$conversation->id} from {$from}" . ($startsAiControlled ? ' [AI-controlled]' : ''));
        }

        if ($previousAgentId) {
            $agentReady = AgentSession::where('user_id', $previousAgentId)
                ->where('status', 'ready')
                ->exists();

            if (!$agentReady) {
                $conversation->update(['status' => 'pending', 'wait_since' => now()]);
                Log::info("WhatsApp webhook: conversation #{$conversation->id} — agent #{$previousAgentId} not ready, held pending");
            }
        }

        $message = Message::create([
            'tenant_id'       => $account->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'from_email'      => $displayPhone,
            'from_name'       => $contactName ?? $displayPhone,
            'to_email'        => $account->phone_number,
            'body_text'       => $bodyText,
            'body_html'       => null,
            'media_type'      => $isMedia ? $type : null,
            'message_id'      => $wamid,
            'sent_at'         => $sentAt,
        ]);

        // Mensaje originado por un anuncio de Click-to-WhatsApp (FB/IG)
        if ($referral = ($msgData['referral'] ?? null)) {
            $referralData = [
                'source_url'      => $referral['source_url'] ?? null,
                'source_type'     => $referral['source_type'] ?? null,
                'headline'        => $referral['headline'] ?? null,
                'body'            => $referral['body'] ?? null,
                'media_type'      => $referral['media_type'] ?? null,
                'ctwa_clid'       => $referral['ctwa_clid'] ?? null,
                'welcome_message' => $referral['welcome_message']['text'] ?? null,
            ];

            if (($referral['media_type'] ?? null) === 'image' && !empty($referral['image_url'])) {
                $crmFile = $this->uploadReferralImage($referral['image_url'], $message);
                $referralData['crm_file_id'] = $crmFile?->id;
            }

            $message->update(['referral_data' => $referralData]);
        }

        // Descargar y subir media a R2 si aplica
        $mediaUrl = null;
        if ($isMedia && $account->access_token) {
            $crmFile  = $this->uploadWaMedia($type, $msgData, $message, $account);
            $mediaUrl = $crmFile ? $this->generateMediaUrl($crmFile) : null;
        }

        // Notificar en tiempo real via Pusher
        if ($conversation->assigned_to && in_array($conversation->status, ['active', 'pending'])) {
            $agentReady = AgentSession::where('user_id', $conversation->assigned_to)
                ->where('status', 'ready')
                ->exists();

            if ($agentReady) {
                event(new NewMessageReceived($message, $conversation, $type !== 'text' ? $type : null, $mediaUrl));
            }
        }

        Log::info("WhatsApp webhook: saved message from {$from} [{$type}] conv #{$conversation->id}");

        // Optional per-tenant, per-channel forwarding to an external AI/automation
        // webhook. Fully gated by conversation_automation_channels (or the legacy
        // tenants.settings->whatsapp_ai_forwarding fallback); no-op when absent/disabled.
        $aiBotActive = Conversation::withoutGlobalScope(TenantScope::class)
            ->whereKey($conversation->id)
            ->value('ai_bot_active');

        if ((bool) $aiBotActive) {
            $this->automationForwardingService->forwardInboundMessage($account->tenant_id, 'whatsapp', [
                'event'                => 'whatsapp.inbound_message',
                'tenant_id'            => $account->tenant_id,
                'tenant_slug'          => $account->tenant?->slug,
                'channel'              => 'whatsapp',
                'conversation_id'      => $conversation->id,
                'message_id'           => $message->id,
                'external_message_id'  => $wamid,
                'from'                 => $from,
                'to'                   => $account->phone_number,
                'contact_name'         => $contactName,
                'message_type'         => $type,
                'text'                 => $bodyText,
                'received_at'          => $sentAt->toIso8601String(),
                'source'               => 'crm.xedik.cloud',
                'raw'                  => $msgData,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function uploadWaMedia(string $type, array $msgData, Message $message, WhatsappAccount $account): ?CrmFile
    {
        $mediaId = $msgData[$type]['id'] ?? null;
        if (!$mediaId) {
            Log::warning("WhatsApp media: no media_id in [{$type}] for msg #{$message->id}");
            return null;
        }

        $downloaded = $this->downloadWaMedia($mediaId, $account->access_token);
        if (!$downloaded) {
            Log::warning("WhatsApp media: download failed for media_id {$mediaId}");
            return null;
        }

        if (!in_array($downloaded['mime_type'], self::ALLOWED_MIME_TYPES, true)) {
            Log::warning("WhatsApp media: MIME type bloqueado [{$downloaded['mime_type']}] msg #{$message->id} — archivo descartado.");
            return null;
        }

        $originalFilename = match($type) {
            'document' => $msgData['document']['filename'] ?? ('document.' . $this->guessExtension($downloaded['mime_type'])),
            'sticker'  => 'sticker.' . $this->guessExtension($downloaded['mime_type']),
            default    => $type . '_' . $message->id . '.' . $this->guessExtension($downloaded['mime_type']),
        };

        $ext     = $this->guessExtension($downloaded['mime_type']);
        $tmpPath = tempnam(sys_get_temp_dir(), 'wa_') . '.' . $ext;
        file_put_contents($tmpPath, $downloaded['content']);

        try {
            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tmpPath,
                $originalFilename,
                $downloaded['mime_type'],
                null,
                true
            );

            $crmFile = $this->fileService->uploadFile($message->tenant_id, $uploadedFile, [
                'channel'         => 'whatsapp',
                'category'        => $this->waTypeToCategory($type),
                'source'          => 'whatsapp',
                'direction'       => 'inbound',
                'conversation_id' => $message->conversation_id,
                'message_id'      => $message->id,
            ]);

            Log::info("WhatsApp media: uploaded [{$type}] as crm_file #{$crmFile->id} for msg #{$message->id}");
            return $crmFile;

        } catch (\Throwable $e) {
            Log::error("WhatsApp media: upload to R2 failed — " . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Descarga la imagen de preview del anuncio (referral.image_url) y la sube a R2.
     * A diferencia de uploadWaMedia(), la URL ya viene firmada por el CDN de Meta —
     * no requiere el paso previo de resolver media_id vía Graph API.
     */
    private function uploadReferralImage(string $imageUrl, Message $message): ?CrmFile
    {
        $tmpPath = null;

        try {
            $resp = Http::timeout(30)->get($imageUrl);

            if (!$resp->successful()) {
                Log::warning("WhatsApp referral: descarga de imagen de anuncio falló", ['status' => $resp->status()]);
                return null;
            }

            $mimeType = $resp->header('Content-Type') ?: 'application/octet-stream';
            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                Log::warning("WhatsApp referral: MIME tipo bloqueado [{$mimeType}] msg #{$message->id} — preview descartado.");
                return null;
            }

            $ext     = $this->guessExtension($mimeType);
            $tmpPath = tempnam(sys_get_temp_dir(), 'wa_ref_') . '.' . $ext;
            file_put_contents($tmpPath, $resp->body());

            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tmpPath,
                'ad_preview_' . $message->id . '.' . $ext,
                $mimeType,
                null,
                true
            );

            $crmFile = $this->fileService->uploadFile($message->tenant_id, $uploadedFile, [
                'channel'         => 'whatsapp',
                'category'        => 'ad_previews',
                'source'          => 'whatsapp',
                'direction'       => 'inbound',
                'conversation_id' => $message->conversation_id,
                'message_id'      => $message->id,
            ]);

            Log::info("WhatsApp referral: imagen de anuncio guardada como crm_file #{$crmFile->id} para msg #{$message->id}");
            return $crmFile;

        } catch (\Throwable $e) {
            Log::error("WhatsApp referral: exception descargando imagen de anuncio — " . $e->getMessage());
            return null;
        } finally {
            if ($tmpPath) {
                @unlink($tmpPath);
            }
        }
    }

    private function downloadWaMedia(string $mediaId, string $accessToken): ?array
    {
        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');

        try {
            // Paso 1: obtener URL de descarga del media
            $infoResp = Http::withToken($accessToken)
                ->timeout(15)
                ->get("{$apiBase}/{$mediaId}");

            if (!$infoResp->successful()) {
                Log::warning("WhatsApp media info failed", ['status' => $infoResp->status(), 'media_id' => $mediaId]);
                return null;
            }

            $mediaUrl = $infoResp->json('url');
            $mimeType = $infoResp->json('mime_type', 'application/octet-stream');

            if (!$mediaUrl) {
                Log::warning("WhatsApp media: no url in info response for {$mediaId}");
                return null;
            }

            // Paso 2: descargar el archivo binario
            $fileResp = Http::withToken($accessToken)
                ->timeout(60)
                ->get($mediaUrl);

            if (!$fileResp->successful()) {
                Log::warning("WhatsApp media download failed", ['status' => $fileResp->status()]);
                return null;
            }

            return [
                'content'   => $fileResp->body(),
                'mime_type' => $mimeType,
            ];

        } catch (\Throwable $e) {
            Log::error("WhatsApp media: exception downloading {$mediaId} — " . $e->getMessage());
            return null;
        }
    }

    private function generateMediaUrl(CrmFile $crmFile): ?string
    {
        try {
            return $this->fileService->generateTemporaryUrl($crmFile->id, null, ttlMinutes: 60);
        } catch (\Throwable $e) {
            Log::warning("WhatsApp media: temp URL generation failed for file #{$crmFile->id} — " . $e->getMessage());
            return null;
        }
    }

    private function waTypeToCategory(string $type): string
    {
        return match($type) {
            'image'    => 'images',
            'audio'    => 'audio',
            'video'    => 'video',
            'document' => 'documents',
            'sticker'  => 'stickers',
            default    => 'other',
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
     * Texto de placeholder para el body_text del mensaje.
     * Se muestra en la lista de conversaciones y en el preview de Pusher.
     */
    private function extractBody(array $msgData, string $type): string
    {
        return match($type) {
            'text'        => $msgData['text']['body'] ?? '',
            'image'       => '[📷 Image]',
            'audio'       => '[🎵 Audio]',
            'video'       => '[🎥 Video]',
            'document'    => '[📄 ' . ($msgData['document']['filename'] ?? 'Document') . ']',
            'sticker'     => '[Sticker]',
            'location'    => '[📍 Location: lat ' . ($msgData['location']['latitude'] ?? '') . ', lng ' . ($msgData['location']['longitude'] ?? '') . ']',
            'contacts'    => '[👤 Contact shared]',
            'interactive' => $msgData['interactive']['button_reply']['title']
                          ?? $msgData['interactive']['list_reply']['title']
                          ?? '[Interactive response]',
            'button'      => $msgData['button']['text'] ?? '[Button]',
            default       => "[{$type}]",
        };
    }
}
