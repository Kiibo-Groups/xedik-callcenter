<?php

declare(strict_types=1);

namespace App\Services\Appointments;

use App\Helpers\PhoneHelper;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\DialerCampaign;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Script;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppointmentNotificationService
{
    /**
     * Enviar mensaje de confirmación inmediata al agendar una cita.
     */
    public function sendConfirmation(Call $call): array
    {
        if (!$call->is_scheduled || empty($call->scheduled_at)) {
            return ['ok' => false, 'error' => 'Call is not scheduled'];
        }

        $lead = $call->lead ?: Lead::withoutGlobalScopes()->find($call->lead_id);
        if (!$lead || empty($lead->phone)) {
            return ['ok' => false, 'error' => 'Lead has no phone number'];
        }

        // 1. Verificar si la campaña o guion tiene habilitada la confirmación
        $config = $this->resolveAppointmentConfig($call);
        if (!$config['send_confirmation']) {
            Log::info("WhatsApp confirmation skipped by campaign/script configuration for Call #{$call->id}");
            return ['ok' => false, 'error' => 'Confirmation disabled for this campaign/script'];
        }

        // 2. Resolver cuenta de WhatsApp
        $account = $this->resolveWhatsappAccount($lead->tenant_id ?? $call->tenant_id);
        if (!$account) {
            Log::warning("No active WhatsApp account found for Tenant {$call->tenant_id}");
            return ['ok' => false, 'error' => 'No active WhatsApp account found'];
        }

        // 3. Formatear teléfono para Meta WhatsApp (ej. 521XXXXXXXXXX)
        $waPhone = $this->formatWaPhone($lead->phone);
        $dateFormatted = Carbon::parse($call->scheduled_at)->locale('es')->isoFormat('dddd D [de] MMMM [a las] h:mm A');
        $leadName = trim($lead->first_name . ' ' . $lead->last_name) ?: 'Cliente';

        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');
        $wamid = null;
        $bodyText = "🗓️ Hola {$leadName}, confirmamos tu cita para el {$dateFormatted}. En breve un asesor se pondrá en contacto contigo.";

        // 4. Enviar SIEMPRE primero con plantilla oficial aprobada de Meta (funciona con o sin ventana de 24h abierta)
        $template = $config['confirmation_template'] ?? null;
        if (!$template || $template->name === 'prueba_hello_world') {
            $template = WhatsappTemplate::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('name', 'confirmacion_cita')
                ->first()
                ?: $template
                ?: WhatsappTemplate::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('name', 'prueba_hello_world')
                ->first();
        }

        if ($template) {
            $langCode = $template->language ?: 'es_MX';
            $tplPayload = [
                'name'     => $template->name,
                'language' => ['code' => $langCode],
            ];

            if ($template->name === 'confirmacion_cita') {
                $tplPayload['components'] = [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $leadName],
                            ['type' => 'text', 'text' => $dateFormatted],
                        ]
                    ]
                ];
            }

            $response = Http::withToken($account->access_token)
                ->timeout(10)
                ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $waPhone,
                    'type'              => 'template',
                    'template'          => $tplPayload,
                ]);

            // Rescate con fallback de idiomas si Meta arroja 132001
            if ($response->failed() && str_contains($response->body(), '132001')) {
                $fallbacks = array_values(array_filter(['es_MX', 'es', 'es_ES', 'es_LA', 'en_US'], fn($l) => $l !== $langCode));
                foreach ($fallbacks as $fbLang) {
                    $tplPayload['language']['code'] = $fbLang;
                    $retry = Http::withToken($account->access_token)
                        ->timeout(10)
                        ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                            'messaging_product' => 'whatsapp',
                            'to'                => $waPhone,
                            'type'              => 'template',
                            'template'          => $tplPayload,
                        ]);
                    if ($retry->successful()) {
                        $response = $retry;
                        $template->update(['language' => $fbLang]);
                        break;
                    }
                }
            }

            if ($response->successful()) {
                $wamid = $response->json('messages.0.id');
            } else {
                Log::error("Failed sending WhatsApp confirmation template for Call #{$call->id}", [
                    'error' => $response->body(),
                    'phone' => $waPhone
                ]);
            }
        }

        // 5. Fallback a texto directo solo si la plantilla no pudo enviarse
        if (!$wamid) {
            try {
                $textResp = Http::withToken($account->access_token)
                    ->timeout(10)
                    ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to'                => $waPhone,
                        'type'              => 'text',
                        'text'              => ['body' => $bodyText],
                    ]);
                if ($textResp->successful()) {
                    $wamid = $textResp->json('messages.0.id');
                }
            } catch (\Throwable $e) {
                Log::warning("Could not send direct text confirmation: " . $e->getMessage());
            }
        }

        if ($wamid) {
            $call->wa_confirmation_sent_at = now();
            $call->save();

            $this->logMessageToCrm($account, $lead, $waPhone, $bodyText, $wamid, $call->agent_id);

            Log::info("WhatsApp appointment confirmation sent successfully to {$waPhone} for Call #{$call->id}");
            return ['ok' => true, 'wamid' => $wamid];
        }

        return ['ok' => false, 'error' => 'Could not deliver WhatsApp confirmation'];
    }

    /**
     * Enviar mensaje de recordatorio el día de la cita/visita.
     */
    public function sendReminder(Call $call): array
    {
        if (!$call->is_scheduled || empty($call->scheduled_at) || $call->completed) {
            return ['ok' => false, 'error' => 'Call is not pending appointment'];
        }

        if ($call->wa_reminder_sent_at) {
            return ['ok' => false, 'error' => 'Reminder already sent'];
        }

        $lead = $call->lead ?: Lead::withoutGlobalScopes()->find($call->lead_id);
        if (!$lead || empty($lead->phone)) {
            return ['ok' => false, 'error' => 'Lead has no phone number'];
        }

        $config = $this->resolveAppointmentConfig($call);
        if (!$config['send_reminder']) {
            Log::info("WhatsApp reminder skipped by configuration for Call #{$call->id}");
            return ['ok' => false, 'error' => 'Reminder disabled'];
        }

        $account = $this->resolveWhatsappAccount($lead->tenant_id ?? $call->tenant_id);
        if (!$account) {
            return ['ok' => false, 'error' => 'No active WhatsApp account found'];
        }

        $waPhone = $this->formatWaPhone($lead->phone);
        $timeFormatted = Carbon::parse($call->scheduled_at)->format('g:i A');
        $leadName = trim($lead->first_name . ' ' . $lead->last_name) ?: 'Cliente';

        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');
        $wamid = null;
        $bodyText = "🗓️ Hola {$leadName}, confirmamos tu cita para el hoy a las {$timeFormatted}. En breve un asesor se pondrá en contacto contigo.";

        // 1. Enviar SIEMPRE primero con plantilla oficial aprobada de Meta (funciona con o sin ventana de 24h)
        $template = $config['reminder_template'] ?? null;
        if (!$template || $template->name === 'prueba_hello_world') {
            $template = WhatsappTemplate::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('name', 'confirmacion_cita')
                ->first()
                ?: $template
                ?: WhatsappTemplate::withoutGlobalScopes()
                ->where('is_active', true)
                ->where('name', 'prueba_hello_world')
                ->first();
        }

        if ($template) {
            $langCode = $template->language ?: 'es_MX';
            $tplPayload = [
                'name'     => $template->name,
                'language' => ['code' => $langCode],
            ];

            if ($template->name === 'confirmacion_cita') {
                $tplPayload['components'] = [
                    [
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $leadName],
                            ['type' => 'text', 'text' => "hoy a las {$timeFormatted}"],
                        ]
                    ]
                ];
            }

            $response = Http::withToken($account->access_token)
                ->timeout(10)
                ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $waPhone,
                    'type'              => 'template',
                    'template'          => $tplPayload,
                ]);

            if ($response->successful()) {
                $wamid = $response->json('messages.0.id');
            }
        }

        // 2. Fallback a texto directo solo si la plantilla falló
        if (!$wamid) {
            try {
                $textResp = Http::withToken($account->access_token)
                    ->timeout(10)
                    ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to'                => $waPhone,
                        'type'              => 'text',
                        'text'              => ['body' => $bodyText],
                    ]);
                if ($textResp->successful()) {
                    $wamid = $textResp->json('messages.0.id');
                }
            } catch (\Throwable $e) {}
        }

        if ($wamid) {
            $call->wa_reminder_sent_at = now();
            $call->save();

            $this->logMessageToCrm($account, $lead, $waPhone, $bodyText, $wamid, $call->agent_id);

            Log::info("WhatsApp appointment reminder sent successfully to {$waPhone} for Call #{$call->id}");
            return ['ok' => true, 'wamid' => $wamid];
        }

        return ['ok' => false, 'error' => 'Could not deliver WhatsApp reminder'];
    }

    /**
     * Resolver la configuración dinámica de la campaña o guion vinculado.
     */
    private function resolveAppointmentConfig(Call $call): array
    {
        $dialerCampaign = null;
        if (!empty($call->dialer_campaign)) {
            $dialerCampaign = DialerCampaign::withoutGlobalScopes()
                ->where('id', $call->dialer_campaign)
                ->orWhere('name', $call->dialer_campaign)
                ->first();
        }

        $script = null;
        if ($dialerCampaign?->script_id) {
            $script = Script::withoutGlobalScopes()->find($dialerCampaign->script_id);
        }

        $sendConfirmation = true;
        $sendReminder = true;
        $confirmationTemplate = null;
        $reminderTemplate = null;

        if ($dialerCampaign) {
            $sendConfirmation = (bool)($dialerCampaign->appointment_wa_confirmation ?? true);
            $sendReminder = (bool)($dialerCampaign->appointment_wa_reminder ?? true);
            if ($dialerCampaign->confirmation_wa_template_id) {
                $confirmationTemplate = WhatsappTemplate::withoutGlobalScopes()->find($dialerCampaign->confirmation_wa_template_id);
            }
            if ($dialerCampaign->reminder_wa_template_id) {
                $reminderTemplate = WhatsappTemplate::withoutGlobalScopes()->find($dialerCampaign->reminder_wa_template_id);
            }
        } elseif ($script) {
            $sendConfirmation = (bool)($script->appointment_wa_confirmation ?? true);
            $sendReminder = (bool)($script->appointment_wa_reminder ?? true);
            if ($script->confirmation_wa_template_id) {
                $confirmationTemplate = WhatsappTemplate::withoutGlobalScopes()->find($script->confirmation_wa_template_id);
            }
            if ($script->reminder_wa_template_id) {
                $reminderTemplate = WhatsappTemplate::withoutGlobalScopes()->find($script->reminder_wa_template_id);
            }
        }

        return [
            'send_confirmation'     => $sendConfirmation,
            'send_reminder'         => $sendReminder,
            'confirmation_template' => $confirmationTemplate,
            'reminder_template'     => $reminderTemplate,
        ];
    }

    /**
     * Resolver cuenta de WhatsApp activa (primero de tenant, luego fallback).
     */
    private function resolveWhatsappAccount(?int $tenantId): ?WhatsappAccount
    {
        $account = null;
        if ($tenantId) {
            $account = WhatsappAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            $account = WhatsappAccount::withoutGlobalScopes()
                ->where('is_active', true)
                ->first();
        }

        return $account;
    }

    /**
     * Formatear teléfono para la API de WhatsApp de Meta (México: 521XXXXXXXXXX).
     */
    private function formatWaPhone(string $rawPhone): string
    {
        $digits = preg_replace('/\D/', '', $rawPhone);
        if (str_starts_with($digits, '521') && strlen($digits) === 13) {
            return $digits;
        }
        if (str_starts_with($digits, '52') && strlen($digits) === 12) {
            return '521' . substr($digits, 2);
        }
        if (strlen($digits) === 10) {
            return '521' . $digits;
        }
        return $digits;
    }

    /**
     * Registrar el mensaje saliente en la conversación del Lead dentro del CRM.
     */
    private function logMessageToCrm(WhatsappAccount $account, Lead $lead, string $fullPhone, string $text, string $wamid, ?int $agentId): void
    {
        try {
            $targetTenantId = $lead->tenant_id ?? $account->tenant_id;
            $displayPhone = PhoneHelper::formatDisplay($fullPhone);

            $conversation = Conversation::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->where('channel', 'whatsapp')
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'tenant_id'           => $targetTenantId,
                    'channel'             => 'whatsapp',
                    'whatsapp_account_id' => $account->id,
                    'omni_queue_id'       => $account->omni_queue_id,
                    'lead_id'             => $lead->id,
                    'external_id'         => $fullPhone,
                    'from_email'          => $displayPhone,
                    'from_name'           => trim($lead->first_name . ' ' . $lead->last_name) ?: $displayPhone,
                    'subject'             => $displayPhone,
                    'status'              => 'active',
                    'assigned_to'         => $agentId ?: $lead->assigned_to,
                    'assigned_at'         => now(),
                    'wait_since'          => now(),
                ]);
            }

            Message::create([
                'tenant_id'           => $targetTenantId,
                'conversation_id'     => $conversation->id,
                'direction'           => 'outbound',
                'from_email'          => $account->phone_number,
                'from_name'           => 'XEDIK Voicebot Assistant',
                'to_email'            => $fullPhone,
                'body_text'           => $text,
                'body_html'           => null,
                'message_id'          => $wamid,
                'provider_message_id' => $wamid,
                'sent_by'             => $agentId ?: $lead->assigned_to,
                'sent_at'             => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Error saving WhatsApp appointment message to CRM history: " . $e->getMessage());
        }
    }
}
