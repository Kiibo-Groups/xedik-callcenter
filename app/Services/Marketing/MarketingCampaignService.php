<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Helpers\PhoneHelper;
use App\Helpers\SmsSegmentHelper;
use App\Models\Conversation;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\MarketingCampaign;
use App\Models\Message;
use App\Models\SmsChannel;
use App\Models\SmsOptOut;
use App\Models\SmsTemplate;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTemplate;
use App\Services\Sms\DTO\OutboundSmsMessage;
use App\Services\Sms\SmsProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MarketingCampaignService
{
    public function __construct(
        private readonly SmsProviderManager $smsProviderManager,
    ) {}

    /**
     * Start or resume a marketing campaign (populates marketing_campaign_queue if idle/scheduled).
     */
    public function startCampaign(MarketingCampaign $campaign): array
    {
        $leadList = $campaign->leadList;
        if (!$leadList) {
            return ['ok' => false, 'error' => 'La campaña no tiene una Lista de Leads asignada.'];
        }
        if ($leadList->status !== 'ready') {
            return ['ok' => false, 'error' => 'La Lista de Leads no está en estado Ready.'];
        }

        // Resuming from paused when queue already has pending items
        if ($campaign->isPaused()) {
            $pending = DB::table('marketing_campaign_queue')
                ->where('marketing_campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->count();

            if ($pending > 0) {
                $campaign->update(['status' => 'running', 'completed_at' => null]);
                Log::info("MarketingCampaign #{$campaign->id} resumed: {$pending} pending items in queue.");
                return ['ok' => true, 'enqueued' => $pending, 'resumed' => true];
            }
        }

        // Clear any leftover pending items before fresh enqueue
        DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $campaign->id)
            ->delete();

        $tenantId  = (int) $campaign->tenant_id;
        $tableName = $leadList->table_name;
        $channel   = $campaign->channel;

        $dncPhones = [];
        if (in_array($channel, ['sms', 'whatsapp'], true)) {
            $dncPhones = DB::table('dialer_dnc')
                ->where('tenant_id', $tenantId)
                ->pluck('phone')
                ->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))
                ->filter()
                ->values()
                ->all();
        }

        $leads = DB::table($tableName)
            ->select('id', 'phone', 'custom_fields', 'status')
            ->orderBy('id', 'asc')
            ->get();

        if ($leads->isEmpty()) {
            $campaign->update([
                'status'        => 'completed',
                'started_at'    => $campaign->started_at ?? now(),
                'completed_at'  => now(),
                'total_queued'  => 0,
                'sent_count'    => 0,
                'failed_count'  => 0,
                'skipped_count' => 0,
            ]);
            return ['ok' => true, 'enqueued' => 0, 'message' => 'La lista no tiene registros.'];
        }

        $rows = [];
        $skipped = 0;
        $now = now();

        foreach ($leads as $lead) {
            $custom = [];
            if (!empty($lead->custom_fields)) {
                $decoded = is_string($lead->custom_fields)
                    ? json_decode($lead->custom_fields, true)
                    : (array) $lead->custom_fields;
                if (is_array($decoded)) {
                    $custom = $decoded;
                }
            }

            $recipientName = $this->extractNameFromCustom($custom);

            if ($channel === 'email') {
                $email = $this->extractEmailFromLead((string) ($lead->phone ?? ''), $custom);
                if (!$email) {
                    $skipped++;
                    continue;
                }
                $recipient = $email;
            } else {
                $rawPhone = (string) ($lead->phone ?? '');
                $digits   = preg_replace('/\D+/', '', $rawPhone);
                if (strlen($digits) < 7) {
                    $skipped++;
                    continue;
                }
                if (!empty($dncPhones) && in_array($digits, $dncPhones, true)) {
                    $skipped++;
                    continue;
                }
                if ($channel === 'sms') {
                    $norm = PhoneHelper::normalizeForSearch($rawPhone);
                    if ($norm && SmsOptOut::isOptedOut($tenantId, $norm)) {
                        $skipped++;
                        continue;
                    }
                }
                $recipient = $rawPhone;
            }

            $custom['phone'] = (string) ($lead->phone ?? '');
            if ($recipientName && empty($custom['name'])) {
                $custom['name'] = $recipientName;
            }

            $rows[] = [
                'tenant_id'             => $tenantId,
                'marketing_campaign_id' => $campaign->id,
                'lead_list_table'       => $tableName,
                'lead_row_id'           => $lead->id,
                'recipient'             => $recipient,
                'recipient_name'        => $recipientName,
                'variables'             => json_encode($custom, JSON_UNESCAPED_UNICODE),
                'status'                => 'pending',
                'attempts'              => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        if (empty($rows)) {
            $campaign->update([
                'status'        => 'completed',
                'started_at'    => $campaign->started_at ?? now(),
                'completed_at'  => now(),
                'total_queued'  => 0,
                'sent_count'    => 0,
                'failed_count'  => 0,
                'skipped_count' => $skipped,
            ]);
            return [
                'ok'       => true,
                'enqueued' => 0,
                'message'  => "No se encontraron destinatarios válidos para {$channel} ({$skipped} omitidos).",
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('marketing_campaign_queue')->insert($chunk);
        }

        $campaign->update([
            'status'        => 'running',
            'started_at'    => $campaign->started_at ?? now(),
            'completed_at'  => null,
            'total_queued'  => count($rows),
            'sent_count'    => 0,
            'failed_count'  => 0,
            'skipped_count' => $skipped,
        ]);

        Log::info("MarketingCampaign #{$campaign->id} ({$channel}) started: " . count($rows) . " queued, {$skipped} skipped.");

        return ['ok' => true, 'enqueued' => count($rows), 'skipped' => $skipped];
    }

    /**
     * Check scheduled marketing campaigns and launch them when due.
     */
    public function processScheduledCampaigns(): int
    {
        $due = MarketingCampaign::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $started = 0;
        foreach ($due as $campaign) {
            Log::info("Launching scheduled MarketingCampaign #{$campaign->id} ('{$campaign->name}')");
            $res = $this->startCampaign($campaign);
            if ($res['ok'] ?? false) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * Auto-pause campaigns outside daily window or past scheduled_end_at; auto-resume inside window.
     */
    public function checkDailyWindowsAndAutoPause(): void
    {
        $now     = now();
        $nowTime = $now->format('H:i:s');

        $running = MarketingCampaign::withoutGlobalScopes()
            ->where('status', 'running')
            ->get();

        foreach ($running as $campaign) {
            if ($campaign->scheduled_end_at && $now->greaterThanOrEqualTo($campaign->scheduled_end_at)) {
                $campaign->update(['status' => 'paused']);
                Log::info("MarketingCampaign #{$campaign->id} auto-paused: reached scheduled_end_at.");
                continue;
            }

            if ($campaign->daily_start_time && $campaign->daily_end_time) {
                if ($nowTime < $campaign->daily_start_time || $nowTime > $campaign->daily_end_time) {
                    $campaign->update(['status' => 'paused']);
                    Log::info("MarketingCampaign #{$campaign->id} auto-paused: outside daily window.");
                }
            }
        }

        $paused = MarketingCampaign::withoutGlobalScopes()
            ->where('status', 'paused')
            ->whereNotNull('daily_start_time')
            ->whereNotNull('daily_end_time')
            ->get();

        foreach ($paused as $campaign) {
            if ($campaign->scheduled_end_at && $now->greaterThanOrEqualTo($campaign->scheduled_end_at)) {
                continue;
            }
            if ($nowTime >= $campaign->daily_start_time && $nowTime <= $campaign->daily_end_time) {
                $pending = DB::table('marketing_campaign_queue')
                    ->where('marketing_campaign_id', $campaign->id)
                    ->where('status', 'pending')
                    ->count();
                if ($pending > 0) {
                    $campaign->update(['status' => 'running']);
                    Log::info("MarketingCampaign #{$campaign->id} auto-resumed inside daily window.");
                }
            }
        }
    }

    /**
     * Process a batch for all running marketing campaigns.
     */
    public function processRunningCampaigns(): void
    {
        $campaigns = MarketingCampaign::withoutGlobalScopes()
            ->where('status', 'running')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                $this->dispatchBatch($campaign);
            } catch (\Throwable $e) {
                Log::error("MarketingCampaign #{$campaign->id} dispatch error: " . $e->getMessage());
            }
        }
    }

    /**
     * Dispatch up to batch_size items for a single MarketingCampaign.
     */
    public function dispatchBatch(MarketingCampaign $campaign): int
    {
        $batchSize = max(1, min(100, (int) ($campaign->batch_size ?: 20)));

        $items = DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        if ($items->isEmpty()) {
            $sendingCount = DB::table('marketing_campaign_queue')
                ->where('marketing_campaign_id', $campaign->id)
                ->where('status', 'sending')
                ->count();

            if ($sendingCount === 0) {
                $this->refreshCounters($campaign, true);
                Log::info("MarketingCampaign #{$campaign->id} completed.");
            }
            return 0;
        }

        $processed = 0;

        foreach ($items as $item) {
            DB::table('marketing_campaign_queue')
                ->where('id', $item->id)
                ->update([
                    'status'     => 'sending',
                    'attempts'   => (int) $item->attempts + 1,
                    'updated_at' => now(),
                ]);

            $vars = [];
            if (!empty($item->variables)) {
                $decoded = json_decode((string) $item->variables, true);
                if (is_array($decoded)) {
                    $vars = $decoded;
                }
            }

            try {
                $providerMsgId = match ($campaign->channel) {
                    'email'    => $this->sendEmailItem($campaign, $item, $vars),
                    'sms'      => $this->sendSmsItem($campaign, $item, $vars),
                    'whatsapp' => $this->sendWhatsappItem($campaign, $item, $vars),
                    default    => throw new \RuntimeException("Unsupported channel: {$campaign->channel}"),
                };

                DB::table('marketing_campaign_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status'              => 'sent',
                        'provider_message_id' => $providerMsgId,
                        'error_message'       => null,
                        'sent_at'             => now(),
                        'updated_at'          => now(),
                    ]);

                // Update lead row status in dynamic table if present
                if ($item->lead_list_table && $item->lead_row_id) {
                    try {
                        DB::table($item->lead_list_table)
                            ->where('id', $item->lead_row_id)
                            ->update([
                                'status'           => 'ANSWER',
                                'called_count'     => DB::raw('called_count + 1'),
                                'last_called_at'   => now(),
                                'last_disposition' => strtoupper($campaign->channel) . '_SENT',
                            ]);
                    } catch (\Throwable $_) {}
                }

                $processed++;
            } catch (\Throwable $e) {
                $attempts   = (int) $item->attempts + 1;
                $maxRetries = max(1, (int) ($campaign->max_retries ?: 1));
                $newStatus  = $attempts < $maxRetries ? 'pending' : 'failed';

                DB::table('marketing_campaign_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status'        => $newStatus,
                        'error_message' => Str::limit($e->getMessage(), 500),
                        'updated_at'    => now(),
                    ]);

                if ($newStatus === 'failed' && $item->lead_list_table && $item->lead_row_id) {
                    try {
                        DB::table($item->lead_list_table)
                            ->where('id', $item->lead_row_id)
                            ->update([
                                'status'           => 'FAILED',
                                'called_count'     => DB::raw('called_count + 1'),
                                'last_called_at'   => now(),
                                'last_disposition' => strtoupper($campaign->channel) . '_FAILED',
                            ]);
                    } catch (\Throwable $_) {}
                }

                Log::warning("MarketingCampaign #{$campaign->id} item #{$item->id} failed: " . $e->getMessage());
            }
        }

        $this->refreshCounters($campaign, false);

        return $processed;
    }

    /**
     * Refresh aggregated counters on the campaign model.
     */
    public function refreshCounters(MarketingCampaign $campaign, bool $markCompletedIfDone = false): array
    {
        $counts = DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts['pending'] ?? 0);
        $sending = (int) ($counts['sending'] ?? 0);
        $sent    = (int) ($counts['sent'] ?? 0);
        $failed  = (int) ($counts['failed'] ?? 0);
        $skipped = (int) $campaign->skipped_count + (int) ($counts['skipped'] ?? 0);
        $total   = $pending + $sending + $sent + $failed;

        $update = [
            'total_queued' => $total > 0 ? $total : $campaign->total_queued,
            'sent_count'   => $sent,
            'failed_count' => $failed,
        ];

        if ($markCompletedIfDone && $pending === 0 && $sending === 0 && $campaign->status === 'running') {
            $update['status']       = 'completed';
            $update['completed_at'] = now();
        }

        $campaign->update($update);

        return [
            'pending' => $pending,
            'sending' => $sending,
            'sent'    => $sent,
            'failed'  => $failed,
            'skipped' => $skipped,
            'total'   => $update['total_queued'],
        ];
    }

    // ── Channel Dispatchers ───────────────────────────────────────────────────

    private function sendEmailItem(MarketingCampaign $campaign, object $item, array $vars): ?string
    {
        $account = EmailAccount::withoutGlobalScopes()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('active', true)
            ->when($campaign->email_account_id, fn ($q) => $q->where('id', $campaign->email_account_id))
            ->first();

        if (!$account) {
            throw new \RuntimeException('No hay cuenta de correo activa configurada para este tenant.');
        }

        $template = $campaign->email_template_id
            ? EmailTemplate::withoutGlobalScopes()->find($campaign->email_template_id)
            : null;

        $rawSubject = $campaign->custom_subject ?: ($template?->subject ?? $campaign->name);
        $rawBody    = $campaign->custom_body ?: ($template?->body ?? '');

        if (trim($rawBody) === '') {
            throw new \RuntimeException('El cuerpo del correo está vacío.');
        }

        $subject = $this->renderVariables($rawSubject, (string) $item->recipient, (string) ($item->recipient_name ?? ''), $vars);
        $body    = $this->renderVariables($rawBody, (string) $item->recipient, (string) ($item->recipient_name ?? ''), $vars);

        $encryption = strtolower((string) $account->smtp_security);
        $tls        = $encryption === 'ssl';
        $transport  = new EsmtpTransport(
            (string) $account->smtp_host,
            (int) ($account->smtp_port ?: 587),
            $tls
        );
        if ($account->require_auth !== false) {
            $transport->setUsername((string) ($account->smtp_username ?: $account->email));
            $transport->setPassword((string) ($account->smtp_password ?: $account->password));
        }

        $mailer    = new Mailer($transport);
        $domain    = str_contains((string) $account->email, '@') ? explode('@', (string) $account->email)[1] : 'crm.xedik.com';
        $messageId = Str::uuid()->toString() . '@' . $domain;

        $fromEmail = $account->from_email ?: $account->email;
        $fromName  = $account->from_name ?: $account->name;

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to((string) $item->recipient)
            ->subject($subject)
            ->html($body)
            ->text(strip_tags($body));

        if (!empty($account->reply_to)) {
            $email->replyTo($account->reply_to);
        }

        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        $mailer->send($email);

        if ($campaign->open_conversation) {
            $conversation = Conversation::withoutGlobalScopes()->create([
                'tenant_id'        => $campaign->tenant_id,
                'channel'          => 'email',
                'email_account_id' => $account->id,
                'omni_queue_id'    => $account->omni_queue_id,
                'campaign_id'      => $campaign->campaign_id,
                'from_email'       => (string) $item->recipient,
                'from_name'        => $item->recipient_name ?: (string) $item->recipient,
                'subject'          => $subject,
                'status'           => 'active',
                'direction'        => 'outbound',
                'ai_bot_active'    => true,
                'wait_since'       => now(),
            ]);

            Message::withoutGlobalScopes()->create([
                'tenant_id'       => $campaign->tenant_id,
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_email'      => $fromEmail,
                'from_name'       => $fromName,
                'to_email'        => (string) $item->recipient,
                'body_html'       => $body,
                'body_text'       => strip_tags($body),
                'message_id'      => $messageId,
                'sent_at'         => now(),
            ]);
        }

        return $messageId;
    }

    private function sendSmsItem(MarketingCampaign $campaign, object $item, array $vars): ?string
    {
        $channel = SmsChannel::withoutGlobalScopes()
            ->with('providerAccount')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('is_active', true)
            ->when($campaign->sms_channel_id, fn ($q) => $q->where('id', $campaign->sms_channel_id))
            ->first();

        if (!$channel || !$channel->providerAccount) {
            throw new \RuntimeException('No hay canal SMS activo configurado para este tenant.');
        }

        $template = $campaign->sms_template_id
            ? SmsTemplate::withoutGlobalScopes()->find($campaign->sms_template_id)
            : null;

        $rawBody = $campaign->custom_body ?: ($template?->body ?? '');
        if (trim($rawBody) === '') {
            throw new \RuntimeException('El texto del SMS está vacío.');
        }

        $body = strip_tags($this->renderVariables($rawBody, (string) $item->recipient, (string) ($item->recipient_name ?? ''), $vars));
        $to   = $this->normalizeE164Phone((string) $item->recipient);

        $provider = $this->smsProviderManager->resolve($channel->providerAccount->provider);
        $result   = $provider->send($channel, $channel->providerAccount, new OutboundSmsMessage(
            from: $channel->address,
            to: $to,
            body: $body,
            campaignId: $campaign->campaign_id,
            templateId: $campaign->sms_template_id,
            metadata: ['marketing_campaign_id' => $campaign->id],
        ));

        if (!$result->success) {
            throw new \RuntimeException($result->errorMessage ?? 'Error del proveedor SMS.');
        }

        if ($campaign->open_conversation) {
            $normalizedTo = PhoneHelper::normalizeForSearch($to);
            $displayPhone = PhoneHelper::formatDisplay($to);

            $conversation = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('channel', 'sms')
                ->where('external_id', $normalizedTo)
                ->whereIn('status', ['active', 'pending'])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::withoutGlobalScopes()->create([
                    'tenant_id'      => $campaign->tenant_id,
                    'channel'        => 'sms',
                    'sms_channel_id' => $channel->id,
                    'omni_queue_id'  => $channel->omni_queue_id,
                    'campaign_id'    => $campaign->campaign_id,
                    'external_id'    => $normalizedTo,
                    'from_email'     => $displayPhone,
                    'from_name'      => $item->recipient_name ?: $displayPhone,
                    'subject'        => $displayPhone,
                    'status'         => 'active',
                    'direction'      => 'outbound',
                    'ai_bot_active'  => true,
                    'wait_since'     => now(),
                ]);
            }

            $segments = SmsSegmentHelper::analyze($body);

            Message::withoutGlobalScopes()->create([
                'tenant_id'           => $campaign->tenant_id,
                'conversation_id'     => $conversation->id,
                'direction'           => 'outbound',
                'from_email'          => $channel->address,
                'from_name'           => $channel->display_name ?: 'SMS Campaign',
                'to_email'            => $to,
                'body_text'           => $body,
                'provider_message_id' => $result->providerMessageId,
                'provider_status'     => 'sent',
                'segments'            => $segments['segments'],
                'sent_at'             => now(),
            ]);
        }

        return $result->providerMessageId;
    }

    private function sendWhatsappItem(MarketingCampaign $campaign, object $item, array $vars): ?string
    {
        $account = WhatsappAccount::withoutGlobalScopes()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('is_active', true)
            ->when($campaign->whatsapp_account_id, fn ($q) => $q->where('id', $campaign->whatsapp_account_id))
            ->first();

        if (!$account) {
            throw new \RuntimeException('No hay cuenta de WhatsApp activa configurada para este tenant.');
        }

        $template = $campaign->whatsapp_template_id
            ? WhatsappTemplate::withoutGlobalScopes()->find($campaign->whatsapp_template_id)
            : WhatsappTemplate::withoutGlobalScopes()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('is_active', true)
                ->first();

        if (!$template) {
            throw new \RuntimeException('No se encontró una plantilla de WhatsApp seleccionada.');
        }

        $apiBase  = rtrim((string) env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v21.0'), '/');
        $toDigits = $this->normalizeWhatsappPhone((string) $item->recipient);
        $langCode = $template->language ?: 'es_MX';

        // Detect how many {{1}}, {{2}} placeholders exist in template body_preview
        $preview = (string) ($template->body_preview ?? '');
        preg_match_all('/\{\{(\d+)\}\}/', $preview, $matches);
        $paramIndices = !empty($matches[1]) ? array_unique(array_map('intval', $matches[1])) : [];
        sort($paramIndices);

        $parameters = [];
        foreach ($paramIndices as $idx) {
            if ($idx === 1) {
                $val = $item->recipient_name ?: ($vars['name'] ?? $vars['nombre'] ?? 'Estimado cliente');
            } else {
                $val = $vars["param_{$idx}"] ?? $vars[(string) $idx] ?? '—';
            }
            $parameters[] = ['type' => 'text', 'text' => (string) $val];
        }

        $templatePayload = [
            'name'     => $template->name,
            'language' => ['code' => $langCode],
        ];
        if (!empty($parameters)) {
            $templatePayload['components'] = [
                [
                    'type'       => 'body',
                    'parameters' => $parameters,
                ],
            ];
        }

        $response = Http::withToken($account->access_token)
            ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $toDigits,
                'type'              => 'template',
                'template'          => $templatePayload,
            ]);

        // Fallback for translation code mismatch (132001)
        if ($response->failed() && str_contains($response->body(), '132001')) {
            $fallbackLangs = array_values(array_filter(['es_MX', 'es', 'es_ES', 'es_LA', 'en_US', 'en'], fn ($l) => $l !== $langCode));
            foreach ($fallbackLangs as $fallbackLang) {
                $templatePayload['language']['code'] = $fallbackLang;
                $retryResp = Http::withToken($account->access_token)
                    ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to'                => $toDigits,
                        'type'              => 'template',
                        'template'          => $templatePayload,
                    ]);
                if ($retryResp->successful()) {
                    $response = $retryResp;
                    $template->update(['language' => $fallbackLang]);
                    break;
                }
            }
        }

        // Fallback if template in Meta has 0 parameters (132000)
        if ($response->failed() && str_contains($response->body(), '132000') && isset($templatePayload['components'])) {
            unset($templatePayload['components']);
            $retryNoParams = Http::withToken($account->access_token)
                ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $toDigits,
                    'type'              => 'template',
                    'template'          => $templatePayload,
                ]);
            if ($retryNoParams->successful()) {
                $response = $retryNoParams;
            }
        }

        if ($response->failed()) {
            throw new \RuntimeException('Meta WhatsApp API Error: ' . $response->body());
        }

        $wamid = $response->json('messages.0.id');

        // Render preview text with resolved parameters so CRM & AI bot see exact message sent
        $renderedPreview = $preview ?: "Plantilla WhatsApp: {$template->display_name}";
        foreach ($parameters as $i => $param) {
            $num = $i + 1;
            $renderedPreview = str_replace("{{{$num}}}", $param['text'], $renderedPreview);
        }

        if ($campaign->open_conversation) {
            $displayPhone = PhoneHelper::formatDisplay($toDigits);

            $conversation = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('channel', 'whatsapp')
                ->where('whatsapp_account_id', $account->id)
                ->where('external_id', $toDigits)
                ->whereIn('status', ['active', 'pending'])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::withoutGlobalScopes()->create([
                    'tenant_id'           => $campaign->tenant_id,
                    'channel'             => 'whatsapp',
                    'whatsapp_account_id' => $account->id,
                    'omni_queue_id'       => $account->omni_queue_id,
                    'campaign_id'         => $campaign->campaign_id,
                    'external_id'         => $toDigits,
                    'from_email'          => $displayPhone,
                    'from_name'           => $item->recipient_name ?: $displayPhone,
                    'subject'             => $displayPhone,
                    'status'              => 'active',
                    'direction'           => 'outbound',
                    'ai_bot_active'       => true,
                    'wait_since'          => now(),
                ]);
            }

            Message::withoutGlobalScopes()->create([
                'tenant_id'       => $campaign->tenant_id,
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_email'      => $account->phone_number,
                'from_name'       => $account->display_name ?: 'WhatsApp Campaign',
                'to_email'        => $toDigits,
                'body_text'       => $renderedPreview,
                'message_id'      => $wamid,
                'sent_at'         => now(),
            ]);
        }

        return $wamid;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractNameFromCustom(array $custom): ?string
    {
        $nameKeys = ['name', 'nombre', 'full_name', 'nombre_completo', 'cliente', 'contacto', 'Name', 'Nombre'];
        foreach ($nameKeys as $k) {
            if (!empty($custom[$k]) && is_scalar($custom[$k])) {
                return trim((string) $custom[$k]);
            }
        }

        $first = $custom['first_name'] ?? $custom['First Name'] ?? $custom['primer_nombre'] ?? '';
        $last  = $custom['last_name'] ?? $custom['Last Name'] ?? $custom['apellido'] ?? '';
        $combined = trim("{$first} {$last}");

        return $combined !== '' ? $combined : null;
    }

    private function extractEmailFromLead(string $phoneCol, array $custom): ?string
    {
        $emailKeys = ['email', 'correo', 'mail', 'e-mail', 'Email', 'Correo', 'E-mail', 'EMAIL'];
        foreach ($emailKeys as $k) {
            if (!empty($custom[$k]) && is_scalar($custom[$k])) {
                $candidate = trim((string) $custom[$k]);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }

        foreach ($custom as $val) {
            if (is_scalar($val)) {
                $candidate = trim((string) $val);
                if (str_contains($candidate, '@') && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }

        if (str_contains($phoneCol, '@') && filter_var(trim($phoneCol), FILTER_VALIDATE_EMAIL)) {
            return trim($phoneCol);
        }

        return null;
    }

    private function renderVariables(string $text, string $recipient, string $recipientName, array $vars): string
    {
        $name  = $recipientName !== '' ? $recipientName : ($vars['name'] ?? $vars['nombre'] ?? 'Cliente');
        $email = str_contains($recipient, '@') ? $recipient : ($vars['email'] ?? $vars['correo'] ?? '');
        $phone = !str_contains($recipient, '@') ? $recipient : ($vars['phone'] ?? $vars['telefono'] ?? '');

        $parts     = explode(' ', $name, 2);
        $firstName = $parts[0] ?? $name;
        $lastName  = $parts[1] ?? '';

        $replacements = [
            '{{name}}'            => $name,
            '{{nombre}}'          => $name,
            '{nombre}'            => $name,
            '{name}'              => $name,
            '{{first_name}}'      => $firstName,
            '{{lead.first_name}}' => $firstName,
            '{{lead.last_name}}'  => $lastName,
            '{{lead.email}}'      => $email,
            '{{lead.phone}}'      => $phone,
            '{{email}}'           => $email,
            '{{correo}}'          => $email,
            '{email}'             => $email,
            '{correo}'            => $email,
            '{{phone}}'           => $phone,
            '{{telefono}}'        => $phone,
            '{telefono}'          => $phone,
            '{phone}'             => $phone,
            '{{leadlist.phone}}'  => $phone,
            '{{date.today}}'      => now()->format('d/m/Y'),
        ];

        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $strVal = (string) $v;
                $replacements["{{{$k}}}"]          = $strVal;
                $replacements["{{$k}}"]            = $strVal;
                $replacements["{{leadlist.{$k}}}"] = $strVal;
                $replacements["{{lead.{$k}}}"]     = $strVal;
            }
        }

        return strtr($text, $replacements);
    }

    private function normalizeE164Phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) {
            return '+52' . $digits;
        }
        return '+' . ltrim($digits, '+');
    }

    private function normalizeWhatsappPhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) {
            return '521' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '52') && !str_starts_with($digits, '521')) {
            return '521' . substr($digits, 2);
        }
        return $digits;
    }
}

