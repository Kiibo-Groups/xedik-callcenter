<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Helpers\PhoneHelper;
use App\Helpers\SmsSegmentHelper;
use App\Events\NewMessageReceived;
use App\Models\AgentSession;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\DialerDisposition;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Models\SmsChannel;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Services\Automation\ConversationAutomationForwardingService;
use App\Services\Automation\ConversationResolutionService;
use App\Services\Sms\DTO\OutboundSmsMessage;
use App\Services\Sms\Exceptions\SmsOptOutException;
use App\Services\Sms\Exceptions\SmsSendException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador único del canal SMS. Único punto que ConsoleController y
 * SmsWebhookController conocen — nunca un adaptador de proveedor concreto.
 * El motor de asignación (AssignConversations/OmniQueue) no se toca: las
 * conversaciones SMS son channel-agnósticas para ese comando, igual que WhatsApp.
 */
class SmsService
{
    public function __construct(
        private readonly SmsProviderManager $providerManager,
        private readonly ConversationAutomationForwardingService $automationForwardingService,
        private readonly ConversationResolutionService $conversationResolutionService,
    ) {}

    /**
     * Envío P2P iniciado por un agente (Quick Actions). Ownership queda en el
     * agente que envía — la respuesta del lead solo llega a él.
     */
    public function sendP2p(
        SmsChannel $channel,
        string $to,
        string $body,
        User $agent,
        ?int $leadId = null,
        ?int $campaignId = null,
        ?int $templateId = null,
    ): Conversation {
        $normalizedTo = PhoneHelper::normalizeForSearch($to);

        if (SmsOptOut::isOptedOut($channel->tenant_id, $normalizedTo)) {
            throw new SmsOptOutException("Phone {$normalizedTo} has opted out of SMS.");
        }

        // campaign_id llega del request (Quick Actions) — nunca confiar en que
        // pertenece al tenant del canal sin verificarlo. Se limpia en silencio
        // (mismo patrón que OmniQueueController::validated() con script_id
        // cross-campaign) en vez de rechazar el envío por un selector inválido.
        if ($campaignId !== null) {
            $campaignBelongsToTenant = Campaign::withoutGlobalScopes()
                ->where('id', $campaignId)
                ->where('tenant_id', $channel->tenant_id)
                ->exists();

            if (!$campaignBelongsToTenant) {
                Log::warning("SmsService: campaign_id {$campaignId} does not belong to tenant {$channel->tenant_id} — ignored.");
                $campaignId = null;
            }
        }

        $account = $channel->providerAccount;
        $provider = $this->providerManager->resolve($account->provider);

        $result = $provider->send($channel, $account, new OutboundSmsMessage(
            from: $channel->address,
            to: $to,
            body: $body,
            campaignId: $campaignId,
            templateId: $templateId,
            metadata: ['lead_id' => $leadId, 'agent_id' => $agent->id],
        ));

        if (!$result->success) {
            throw new SmsSendException($result->errorMessage ?? 'Unknown SMS provider error.');
        }

        $lead = $leadId ? \App\Models\Lead::find($leadId) : null;
        $displayPhone = PhoneHelper::formatDisplay($to);
        $leadName = $lead ? trim($lead->first_name . ' ' . $lead->last_name) : null;

        $conversation = Conversation::create([
            'tenant_id'      => $channel->tenant_id,
            'channel'        => 'sms',
            'sms_channel_id' => $channel->id,
            'omni_queue_id'  => $channel->omni_queue_id,
            'campaign_id'    => $campaignId,
            'lead_id'        => $lead?->id,
            'external_id'    => $normalizedTo,
            'from_email'     => $displayPhone,
            'from_name'      => $leadName ?? $displayPhone,
            'subject'        => $displayPhone,
            'status'         => 'active',
            'assigned_to'    => $agent->id,
            'assigned_at'    => now(),
            'wait_since'     => now(),
            'direction'      => 'outbound',
        ]);

        $segments = SmsSegmentHelper::analyze($body);

        Message::create([
            'tenant_id'           => $channel->tenant_id,
            'conversation_id'     => $conversation->id,
            'direction'           => 'outbound',
            'from_email'          => $channel->address,
            'from_name'           => $agent->name,
            'to_email'            => $to,
            'body_text'           => $body,
            'provider_message_id' => $result->providerMessageId,
            'provider_status'     => 'sent',
            'segments'            => $segments['segments'],
            'provider_payload'    => $result->raw,
            'sent_by'             => $agent->id,
            'sent_at'             => now(),
        ]);

        return $conversation;
    }

    /**
     * Respuesta del agente dentro de una conversación SMS existente (no crea
     * conversación nueva). Espejo de ConsoleController::sendWhatsapp para el canal SMS.
     */
    public function sendReply(Conversation $conversation, string $body, ?User $agent): Message
    {
        $channel = $conversation->smsChannel;
        if (!$channel) {
            throw new SmsSendException('No SMS channel linked to this conversation.');
        }

        // Opt-out sigue bloqueando cualquier salida iniciada por la empresa
        // (Quick Actions, reply "en frío", automation sin relación) — EXCEPTO
        // cuando el último mensaje de la conversación es un inbound genuino
        // del cliente (no la palabra STOP misma). Esa única condición ya
        // existente (Message.direction) es la ventana: en cuanto se responde,
        // el nuevo último mensaje es outbound y la excepción se cierra sola —
        // un envío posterior sin que el cliente vuelva a escribir se bloquea
        // de nuevo. Aplica igual a reply humano (Consola) y reply de IA,
        // porque ambos comparten este método — ninguno debe reabrir el envío
        // a un número opted-out salvo que exista esa respuesta pendiente.
        if (SmsOptOut::isOptedOut($conversation->tenant_id, PhoneHelper::normalizeForSearch($conversation->external_id ?? ''))
            && !$this->conversationHasPendingCustomerReply($conversation)) {
            throw new SmsOptOutException('This number has opted out of SMS.');
        }

        // conversation->external_id siempre queda reducido a 10 dígitos
        // nacionales (normalizeForSearch(), usado también para el matching de
        // inbound — no se toca ese formato). A diferencia de New SMS, el reply
        // no recibe el country code del request, así que hay que recomponerlo
        // aquí con el MISMO normalizador central (PhoneHelper::composeInternationalDestination)
        // que usa sendP2p()/ConsoleController::sendSmsP2p() — nunca reenviar
        // los 10 dígitos nacionales solos al provider.
        try {
            $countryCode = PhoneHelper::callingCodeForChannel($channel->address, $channel->country);
            $destination = PhoneHelper::composeInternationalDestination($countryCode, $conversation->external_id ?? '');
        } catch (\InvalidArgumentException $e) {
            throw new SmsSendException('Could not determine the international destination for this conversation: ' . $e->getMessage());
        }

        $account  = $channel->providerAccount;
        $provider = $this->providerManager->resolve($account->provider);

        $result = $provider->send($channel, $account, new OutboundSmsMessage(
            from: $channel->address,
            to: $destination,
            body: $body,
        ));

        if (!$result->success) {
            throw new SmsSendException($result->errorMessage ?? 'Unknown SMS provider error.');
        }

        $segments = SmsSegmentHelper::analyze($body);

        return Message::create([
            'tenant_id'           => $conversation->tenant_id,
            'conversation_id'     => $conversation->id,
            'direction'           => 'outbound',
            'from_email'          => $channel->address,
            'from_name'           => $agent?->name ?? 'Bot',
            'to_email'            => $destination,
            'body_text'           => $body,
            'provider_message_id' => $result->providerMessageId,
            'provider_status'     => 'sent',
            'segments'            => $segments['segments'],
            'provider_payload'    => $result->raw,
            'sent_by'             => $agent?->id,
            'sent_at'             => now(),
        ]);
    }

    /**
     * Inbound sin ownership: resuelve canal/tenant por el "to", crea/recupera
     * conversación, y la entrega al motor omnicanal existente (queue + estrategia
     * configurada, incluida "último sin hacer nada" = round robin en AssignConversations).
     */
    public function handleInbound(string $providerKey, array $payload): void
    {
        $provider = $this->providerManager->resolve($providerKey);
        $inbound  = $provider->parseInbound($payload);

        // $inbound->receivedAt es UTC (contrato del DTO, agnóstico de proveedor).
        // Pero config('app.timezone') fuerza date_default_timezone_set() a
        // America/Hermosillo en todo el proceso PHP (LoadConfiguration del
        // framework) — así que CUALQUIER hidratación posterior de esta columna
        // desde un SELECT (Eloquent Carbon::createFromFormat sin tz explícito)
        // reinterpreta el string crudo asumiendo esa misma zona. Todos los demás
        // canales (WhatsApp, Zernio, email, los now() de outbound) ya guardan
        // wall-clock en esa misma zona por esa razón — SMS inbound era el único
        // que guardaba wall-clock UTC, así que al releerse se corría 7h. Alinear
        // aquí el punto de escritura, no el de lectura, mantiene consistente TODA
        // la columna sent_at/wait_since bajo la misma convención sin tocar el
        // resto de canales.
        $receivedAtLocal = $inbound->receivedAt->copy()->setTimezone(config('app.timezone'));

        $normalizedTo   = PhoneHelper::normalizeForSearch($inbound->to);
        $normalizedFrom = PhoneHelper::normalizeForSearch($inbound->from);

        $channel = SmsChannel::withoutGlobalScope(TenantScope::class)
            ->where('normalized_address', $normalizedTo)
            ->where('is_active', true)
            ->first();

        if (!$channel) {
            Log::warning("SmsService: no active SmsChannel for inbound to={$inbound->to}", ['provider' => $providerKey]);
            return;
        }

        $lockKey = 'sms-inbound:' . $channel->tenant_id . ':' . $channel->id . ':' . ($inbound->providerMessageId ?: sha1($normalizedFrom . '|' . $normalizedTo . '|' . $inbound->body));

        $automationPayload = Cache::lock($lockKey, 10)->block(5, function () use (
            $channel,
            $inbound,
            $providerKey,
            $receivedAtLocal,
            $normalizedFrom
        ): ?array {
            return DB::transaction(function () use ($channel, $inbound, $providerKey, $receivedAtLocal, $normalizedFrom): ?array {
                if ($inbound->providerMessageId && $this->inboundProviderMessageAlreadyProcessed($channel, $inbound->providerMessageId)) {
                    Log::info("SmsService: skipping duplicate inbound provider_message_id={$inbound->providerMessageId}");
                    return null;
                }

                $isOptOutKeyword = $this->isStopKeyword($inbound->body);

                $conversation = $this->findConversationForInboundSms($channel, $normalizedFrom, $isOptOutKeyword);

                $previousAgentId = null;
                $displayPhone = PhoneHelper::formatDisplay($inbound->from);

                if ($conversation) {
                    $previousAgentId = $conversation->assigned_to;
                    if (in_array($conversation->status, ['pending', 'active'], true)) {
                        Conversation::withoutTimestamps(fn() => $conversation->update(['wait_since' => now()]));
                    }
                } else {
                    $conversation = Conversation::create([
                        'tenant_id'      => $channel->tenant_id,
                        'channel'        => 'sms',
                        'sms_channel_id' => $channel->id,
                        'omni_queue_id'  => $channel->omni_queue_id,
                        'external_id'    => $normalizedFrom,
                        'from_email'     => $displayPhone,
                        'from_name'      => $displayPhone,
                        'subject'        => $displayPhone,
                        'status'         => 'pending',
                        'wait_since'     => $receivedAtLocal,
                        'direction'      => 'inbound',
                        // El estado de opt-out NUNCA decide esto — solo si la
                        // automatización del canal está habilitada. Un número
                        // opted-out que escribe algo distinto de STOP entra
                        // igual al flujo de IA (o humano si está deshabilitada);
                        // lo único que el opt-out bloquea es el ENVÍO saliente
                        // (ver SmsService::sendReply()), nunca la recepción ni
                        // el ruteo del inbound.
                        'ai_bot_active'  => $this->automationForwardingService->isEnabledForInbound($channel->tenant_id, 'sms'),
                    ]);

                    Log::info("SmsService: new conversation #{$conversation->id} from {$inbound->from}");

                    // omni_queue_id es decisión exclusiva del tenant-admin (nunca del
                    // superadmin al provisionar el canal) — hasta que la configure, la
                    // conversación queda pending sin agente: AssignConversations no puede
                    // enrutarla y no debe hacerlo fallar, solo advertir operacionalmente.
                    if (!$channel->omni_queue_id) {
                        Log::warning("SmsService: conversation #{$conversation->id} created with no omni_queue_id on channel #{$channel->id} ({$channel->address}) — will remain unassigned until the tenant-admin configures a queue.");
                    }
                }

                if ($previousAgentId) {
                    $agentReady = AgentSession::where('user_id', $previousAgentId)
                        ->where('status', 'ready')
                        ->exists();

                    if (!$agentReady && in_array($conversation->status, ['pending', 'active'], true)) {
                        $conversation->update(['status' => 'pending', 'wait_since' => now()]);
                    }
                }

                $message = Message::create([
                    'tenant_id'           => $channel->tenant_id,
                    'conversation_id'     => $conversation->id,
                    'direction'           => 'inbound',
                    'from_email'          => $displayPhone,
                    'from_name'           => $displayPhone,
                    'to_email'            => $channel->address,
                    'body_text'           => $inbound->body,
                    'provider_message_id' => $inbound->providerMessageId,
                    'provider_payload'    => $inbound->raw,
                    'sent_at'             => $receivedAtLocal,
                ]);

                if ($isOptOutKeyword) {
                    $this->handleStopKeyword($channel, $normalizedFrom, $inbound->body, $providerKey, $inbound->providerMessageId);
                }

                if ($conversation->assigned_to && in_array($conversation->status, ['active', 'pending'], true)) {
                    $agentReady = AgentSession::where('user_id', $conversation->assigned_to)
                        ->where('status', 'ready')
                        ->exists();

                    if ($agentReady) {
                        event(new NewMessageReceived($message, $conversation));
                    }
                }

                Log::info("SmsService: saved inbound message from {$inbound->from} conv #{$conversation->id}");

                if ($isOptOutKeyword) {
                    $this->resolveSmsOptOutIfAiControlled($conversation->id, $channel->tenant_id);
                    return null;
                }

                if (!$this->conversationIsAiControlled($conversation->id, $channel->tenant_id)) {
                    return null;
                }

                return [
                    'event'               => 'sms.inbound_message',
                    'tenant_id'           => $channel->tenant_id,
                    'tenant_slug'         => $channel->tenant?->slug,
                    'channel'             => 'sms',
                    'conversation_id'     => $conversation->id,
                    'message_id'          => $message->id,
                    'external_message_id' => $inbound->providerMessageId,
                    'from'                => $inbound->from,
                    'to'                  => $channel->address,
                    'contact_name'        => $conversation->from_name,
                    'message_type'        => 'text',
                    'text'                => $inbound->body,
                    'received_at'         => $message->sent_at?->toIso8601String(),
                    'source'              => rtrim((string) config('app.url'), '/'),
                    'raw'                 => $this->automationSafeRawPayload($providerKey, $inbound->raw),
                ];
            });
        });

        if ($automationPayload !== null) {
            $this->automationForwardingService->forwardInboundMessage($channel->tenant_id, 'sms', $automationPayload);
        }
    }

    public function handleStatusCallback(string $providerKey, array $payload): void
    {
        $provider = $this->providerManager->resolve($providerKey);
        $update   = $provider->parseStatusCallback($payload);

        if (!$update->providerMessageId) {
            Log::warning('SmsService: status callback missing provider_message_id', ['provider' => $providerKey, 'payload' => $payload]);
            return;
        }

        $message = Message::withoutGlobalScope(TenantScope::class)
            ->where('provider_message_id', $update->providerMessageId)
            ->first();

        if (!$message) {
            Log::warning("SmsService: status callback for unknown provider_message_id={$update->providerMessageId}", ['provider' => $providerKey]);
            return;
        }

        // update() es naturalmente idempotente ante reintentos del mismo callback —
        // reescribe los mismos valores, no requiere tabla de deduplicación aparte.
        $message->update([
            'provider_status'        => $update->providerStatus ?? $message->provider_status,
            'provider_error_code'    => $update->errorCode,
            'segments'               => $update->segments ?? $message->segments,
            'provider_cost'          => $update->cost,
            'provider_started_at'    => $update->startedAt,
            'provider_completed_at'  => $update->completedAt,
            'provider_payload'       => $update->raw,
        ]);

        Log::info("SmsService: status callback applied to message #{$message->id} status={$update->providerStatus}");
    }

    private function handleStopKeyword(SmsChannel $channel, string $normalizedFrom, string $body, string $providerKey, ?string $sourceMessageId): bool
    {
        if (!$this->isStopKeyword($body)) {
            return false;
        }

        $normalizedBody = strtoupper(trim($body));

        SmsOptOut::updateOrCreate(
            ['tenant_id' => $channel->tenant_id, 'phone' => $normalizedFrom, 'scope' => 'tenant'],
            [
                'sms_channel_id'     => $channel->id,
                'reason'             => $normalizedBody,
                'provider'           => $providerKey,
                'source_message_id'  => $sourceMessageId,
                'opted_out_at'       => now(),
            ]
        );

        Log::info("SmsService: opt-out registered for {$normalizedFrom} (keyword={$normalizedBody})");

        return true;
    }

    private function isStopKeyword(string $body): bool
    {
        $keywords = config('sms.stop_keywords', ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT']);

        return in_array(strtoupper(trim($body)), $keywords, true);
    }

    /**
     * Única excepción al bloqueo de opt-out en sendReply(): el último mensaje
     * de la conversación es un inbound genuino del cliente (no la keyword STOP
     * misma) — hay una respuesta pendiente que el cliente está esperando. No
     * depende de ninguna columna/estado nuevo, solo de Message.direction ya
     * existente. En cuanto se envía la respuesta, ese nuevo mensaje queda
     * outbound y la ventana se cierra: un envío posterior sin que el cliente
     * vuelva a escribir encuentra el último mensaje en outbound y se bloquea
     * de nuevo — evita que una conversación vieja autorice envíos indefinidos.
     */
    private function conversationHasPendingCustomerReply(Conversation $conversation): bool
    {
        $lastMessage = Message::where('conversation_id', $conversation->id)->latest('id')->first();

        if (!$lastMessage || $lastMessage->direction !== 'inbound') {
            return false;
        }

        return !$this->isStopKeyword((string) $lastMessage->body_text);
    }

    private function inboundProviderMessageAlreadyProcessed(SmsChannel $channel, string $providerMessageId): bool
    {
        return Message::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $channel->tenant_id)
            ->where('direction', 'inbound')
            ->where('provider_message_id', $providerMessageId)
            ->whereHas('conversation', function ($query) use ($channel) {
                $query->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $channel->tenant_id)
                    ->where('channel', 'sms')
                    ->where('sms_channel_id', $channel->id);
            })
            ->lockForUpdate()
            ->exists();
    }

    private function findConversationForInboundSms(SmsChannel $channel, string $normalizedFrom, bool $isOptOutKeyword): ?Conversation
    {
        $query = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel', 'sms')
            ->where('sms_channel_id', $channel->id)
            ->where('external_id', $normalizedFrom);

        // El propio mensaje STOP puede querer resolver/anotar el hilo más
        // reciente aunque ya esté resuelto (ver resolveSmsOptOutIfAiControlled).
        // Cualquier OTRO mensaje (opted-out o no) sigue la regla normal de
        // todos los canales: solo se reutiliza una conversación pending/active;
        // si no hay una, se crea una nueva — el opt-out no altera este ruteo.
        if (!$isOptOutKeyword) {
            $query->whereIn('status', ['pending', 'active']);
        }

        return $query
            ->orderByRaw("CASE WHEN status IN ('active', 'pending') THEN 0 WHEN status = 'resolved' THEN 1 ELSE 2 END")
            ->latest()
            ->lockForUpdate()
            ->first();
    }

    private function conversationIsAiControlled(int $conversationId, int $tenantId): bool
    {
        return (bool) Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->value('ai_bot_active');
    }

    private function resolveSmsOptOutIfAiControlled(int $conversationId, int $tenantId): void
    {
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($conversationId)
            ->first();

        if (!$conversation || !$conversation->ai_bot_active) {
            return;
        }

        $disposition = DialerDisposition::findByName('SMS Opt-Out', $tenantId)?->name ?? 'SMS Opt-Out';

        $this->conversationResolutionService->resolveByAi(
            $conversation,
            $disposition,
            'Customer opted out of SMS.',
        );
    }

    private function automationSafeRawPayload(string $providerKey, array $raw): array
    {
        return [
            'provider' => $providerKey,
            'payload'  => $raw,
        ];
    }
}
