<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Models\PauseReason;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadList;
use App\Models\DialerCampaign;
use App\Helpers\PhoneHelper;
use App\Services\AgentStatusService;
use App\Services\PreviewDialerLeadDispositionSyncService;
use App\Services\Scripts\ConversationScriptResolver;
use App\Services\Telephony\TelephonyCallWrapupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use App\Models\DialerDisposition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;

class ConsoleController extends Controller
{
    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function cleanHtmlBody(string $body): string
    {
        $body = preg_replace('/(<br\s*\/?>\s*)+(?=<table)/i', '', $body);
        $body = preg_replace('/<tbody>\s*(<br\s*\/?>\s*)+/i', '<tbody>', $body);
        $body = preg_replace('/(<br\s*\/?>\s*)+<\/tr>/i', '</tr>', $body);
        $body = preg_replace('/<\/tr>\s*(<br\s*\/?>\s*)+/i', '</tr>', $body);
        $body = preg_replace('/<tr>\s*(<br\s*\/?>\s*)+/i', '<tr>', $body);
        $body = preg_replace('/(<br\s*\/?>\s*)+<td/i', '<td', $body);
        $body = preg_replace('/<\/td>\s*(<br\s*\/?>\s*)+/i', '</td>', $body);
        $body = preg_replace('/border-color:\s*([^;}"]+)/', 'border: 1px solid $1', $body);
        return $body;
    }

    private function extractInlineImages(string &$body): array
    {
        $inlineImages = [];
        $body = preg_replace_callback(
            '/src="data:(image\/[^;]+);base64,([^"]+)"/',
            function($matches) use (&$inlineImages) {
                $mimeType = $matches[1];
                $cid = 'inline_' . uniqid() . '@xedik';
                $inlineImages[] = [
                    'cid'      => $cid,
                    'content'  => base64_decode($matches[2]),
                    'mimeType' => $mimeType,
                ];
                return 'src="cid:' . $cid . '"';
            },
            $body
        );
        return $inlineImages;
    }

    private function buildMailer($account): array
    {
        $transport = new EsmtpTransport(
            $account->smtp_host,
            $account->smtp_port,
            strtolower($account->smtp_security) === 'ssl'
        );
        $transport->setUsername($account->smtp_username ?: $account->email);
        $transport->setPassword($account->smtp_password ?: $account->password);
        $mailer = new Mailer($transport);
        $messageId = bin2hex(random_bytes(16)) . '@' . $account->smtp_host;
        return [$mailer, $messageId];
    }

    /**
     * Apply From / Reply-To headers using account identity settings.
     *
     * - from_email / from_name fall back to auth email / account name when blank.
     * - Reply-To: only set when it adds value (differs from effective From address).
     * Domain comparisons are case-insensitive and whitespace-trimmed.
     */
    private function applyEmailIdentity(Email $email, $account): void
    {
        $effectiveFrom     = $account->from_email ?: $account->email;
        $effectiveFromName = $account->from_name  ?: $account->name;

        $email->from(new Address($effectiveFrom, $effectiveFromName));

        $replyTo = $account->reply_to ?: null;
        if ($replyTo && strtolower(trim($replyTo)) !== strtolower(trim($effectiveFrom))) {
            $email->replyTo($replyTo);
        }
    }

    private function buildQuote($message): string
    {
        $senderName = $message->from_name
            ? $message->from_name . ' (&lt;' . $message->from_email . '&gt;)'
            : $message->from_email;
        $carbonDate = \Carbon\Carbon::parse($message->sent_at)->locale('es');
        $dayName    = $carbonDate->isoFormat('ddd, D MMM YYYY');
        $time       = $carbonDate->format('g:i A');

        if ($message->body_html) {
            return '
                <br><br>
                <div style="border-left:3px solid #ccc;padding-left:12px;color:#666;margin-top:8px;">
                    <div style="font-size:12px;margin-bottom:8px;">
                        El ' . $dayName . ' a las ' . $time . ', ' . $senderName . ' escribió:
                    </div>
                    ' . $message->body_html . '
                </div>';
        } elseif ($message->body_text) {
            return '<br><br><div style="border-left:3px solid #ccc;padding-left:12px;color:#666;">'
                . nl2br(htmlspecialchars($message->body_text))
                . '</div>';
        }
        return '';
    }

    private function embedAllInlineImages(Email $email, string &$fullBody): \Illuminate\Database\Eloquent\Collection
    {
        $allInlineAtts = \App\Models\Attachment::whereNotNull('path')
            ->where('path', 'like', '%/inline/%')
            ->get();

        foreach ($allInlineAtts as $att) {
            if (Storage::disk('local')->exists($att->path) &&
                str_contains($fullBody, '/attachments/' . $att->id . '/view')) {
                $cid = 'img_' . $att->id . '@xedik';
                $content = Storage::disk('local')->get($att->path);
                $email->embed($content, $cid, $att->mime_type);
                $fullBody = preg_replace(
                    '/https?:\/\/[^\/]+\/attachments\/' . $att->id . '\/view[^"\']*/',
                    'cid:' . $cid,
                    $fullBody
                );
            }
        }
        return $allInlineAtts;
    }

    private function embedNewInlineImages(Email $email, array $inlineImages): void
    {
        foreach ($inlineImages as $img) {
            $email->embed($img['content'], $img['cid'], $img['mimeType']);
        }
    }

    private function revertCidsToUrls(string $fullBody, $allInlineAtts): string
    {
        $bodyForDb = $fullBody;
        foreach ($allInlineAtts as $att) {
            $cid = 'img_' . $att->id . '@xedik';
            if (str_contains($fullBody, 'cid:' . $cid)) {
                $bodyForDb = str_replace(
                    'cid:' . $cid,
                    \URL::signedRoute('attachments.view', ['attachment' => $att->id]),
                    $bodyForDb
                );
            }
        }
        return $bodyForDb;
    }

    private function saveInlineImages(array &$inlineImages): void
    {
        foreach ($inlineImages as &$img) {
            $ext = explode('/', $img['mimeType'])[1];
            $tmpPath = 'messages/outbound/tmp/' . uniqid() . '.' . $ext;
            Storage::disk('local')->put($tmpPath, $img['content']);
            $img['tmp_path'] = $tmpPath;
            $img['ext'] = $ext;
        }
        unset($img);
    }

    private function createInlineAttachments(Message $message, array $inlineImages, string &$bodyForDb): void
    {
        foreach ($inlineImages as $img) {
            $att = \App\Models\Attachment::create([
                'tenant_id'  => $message->tenant_id,
                'message_id' => $message->id,
                'filename'   => 'inline.' . $img['ext'],
                'mime_type'  => $img['mimeType'],
                'size'       => strlen($img['content']),
                'path'       => $img['tmp_path'],
                'content_id' => $img['cid'],
            ]);

            $finalPath = 'messages/outbound/' . $message->id . '/inline/' . $att->id . '_inline.' . $img['ext'];
            Storage::disk('local')->move($img['tmp_path'], $finalPath);
            $att->update(['path' => $finalPath]);

            $signedUrl = \URL::signedRoute('attachments.view', ['attachment' => $att->id]);
            $bodyForDb = str_replace(
                'src="cid:' . $img['cid'] . '"',
                'src="' . $signedUrl . '"',
                $bodyForDb
            );
        }
    }

    // ── Guardar adjuntos normales → R2 via CrmFileService ──
    private function saveAttachments(Message $message, Request $request): void
    {
        if (!$request->hasFile('attachments')) return;

        $fileService = app(\App\Services\Storage\CrmFileService::class);

        foreach ($request->file('attachments') as $file) {
            try {
                $crmFile = $fileService->uploadFile($message->tenant_id, $file, [
                    'channel'         => 'email',
                    'category'        => 'attachments',
                    'source'          => 'email_attachment',
                    'direction'       => 'outbound',
                    'conversation_id' => $message->conversation_id,
                    'message_id'      => $message->id,
                    'uploaded_by'     => Auth::id(),
                ]);

                \App\Models\Attachment::create([
                    'tenant_id'   => $message->tenant_id,
                    'message_id'  => $message->id,
                    'filename'    => $file->getClientOriginalName(),
                    'mime_type'   => $file->getMimeType(),
                    'size'        => $file->getSize(),
                    'path'        => '',
                    'crm_file_id' => $crmFile->id,
                ]);

            } catch (\Throwable $e) {
                Log::warning("saveAttachments: R2 upload failed for {$file->getClientOriginalName()} — " . $e->getMessage());
            }
        }
    }

    private function getAgentEmailAccounts()
    {
        return auth()->user()
            ->omniQueues()
            ->with(['emailAccounts' => fn($q) => $q->where('active', true)])
            ->get()
            ->pluck('emailAccounts')
            ->flatten()
            ->unique('id')
            ->values()
            ->map(fn($a) => [
                'id'      => $a->id,
                'email'   => $a->email,
                'name'    => $a->name ?? $a->email,
                'display' => $a->name ? $a->name . ' <' . $a->email . '>' : $a->email,
            ]);
    }

    public function index()
    {
        $user = Auth::user();

        $session = AgentSession::with('pauseReason')
                               ->where('user_id', $user->id)
                               ->first();

        $pauseReasons = PauseReason::active()->get();

        $conversations = Conversation::with([
                                        'lead',
                                        'emailAccount',
                                        'latestVoiceCall',   // needed for initial Voice tray data
                                        'messages' => function($q) { $q->latest()->limit(1); },
                                     ])
                                     ->where('assigned_to', $user->id)
                                     ->whereIn('status', ['active', 'pending'])
                                     ->orderBy('wait_since', 'desc')
                                     ->get()
                                     ->sortByDesc('wait_since')
                                     ->values();

        // Extract Voice conversations formatted for the initial JS payload.
        // Embedded in AgentConsoleVoiceConfig so the adapter can hydrate them
        // synchronously (before any fetch) — they appear at the same time as Email/WhatsApp.
        $initialVoiceConversations = $conversations
            ->filter(fn($c) => $c->channel === 'voice')
            ->values()
            ->map(fn($c) => [
                'id'               => $c->id,
                'external_id'      => $c->external_id,     // call_uuid
                'from_name'        => $c->from_name,
                'from_email'       => $c->from_email,      // normalized remote phone for voice
                'remote_phone'     => $c->from_email,
                'remote_phone_display' => $c->direction === 'outbound'
                    ? $c->latestVoiceCall?->dnis_display
                    : $c->latestVoiceCall?->ani_display,
                'ani_normalized'   => $c->latestVoiceCall?->ani_normalized,
                'dnis_normalized'  => $c->latestVoiceCall?->dnis_normalized,
                'ani_display'      => $c->latestVoiceCall?->ani_display,
                'dnis_display'     => $c->latestVoiceCall?->dnis_display,
                'lead_id'          => $c->lead_id,
                'campaign_id'      => $c->campaign_id,
                'dialer_campaign_id' => $c->dialer_campaign_id,
                'lead_list_id'     => $c->lead_list_id,
                'lead_list_row_id' => $c->lead_list_row_id,
                'lead_name'        => $c->lead ? trim($c->lead->first_name . ' ' . $c->lead->last_name) : null,
                'lead'             => $this->formatVoiceTrayLead($c->lead),
                'direction'        => $c->direction,
                'voice_direction'  => $c->direction,
                'voice_call_state' => $c->latestVoiceCall?->call_state,
                'voice_call_answered_at' => $c->latestVoiceCall?->answered_at?->toISOString(),
                'voice_call_ended_at' => $c->latestVoiceCall?->ended_at?->toISOString(),
                'is_voice_wrap_up' => $c->isVoiceWrapUpPending(),
                'wait_since'       => ($c->wait_since ?? $c->created_at)?->diffForHumans(null, true, true),
            ])
            ->all();

        $leadStatuses = LeadStatus::where('is_active', true)->orderBy('order')->get(['slug', 'name', 'color', 'bg_color']);

        $waTemplates = \App\Models\WhatsappTemplate::where('is_active', true)
                                                   ->orderBy('display_name')
                                                   ->get(['id', 'name', 'display_name', 'language', 'body_preview']);
        if ($waTemplates->isEmpty()) {
            $waTemplates = \App\Models\WhatsappTemplate::withoutGlobalScopes()
                                                       ->where('is_active', true)
                                                       ->orderBy('display_name')
                                                       ->get(['id', 'name', 'display_name', 'language', 'body_preview']);
        }

        // SMS Quick Action: solo los números ELEGIBLES para este agente (tenant +
        // activo + p2p + queue activa + membresía activa del agente + campaign
        // válida). Campaign/Queue ya NO son selectores — se derivan del número.
        $smsEligibleChannels = $this->eligibleSmsChannelsForAgent($user)
            ->map(fn ($ch) => [
                'id'            => (int) $ch->id,
                'display_name'  => $ch->display_name,
                'address'       => $ch->address,
                'omni_queue_id' => (int) $ch->omni_queue_id,
                'queue_name'    => $ch->omniQueue?->name,
                'campaign_id'   => (int) ($ch->omniQueue?->campaign_id ?? 0),
                'campaign_name' => $ch->omniQueue?->campaign?->name,
                'country'       => $ch->country,
            ])
            ->values();

        // Templates activos del tenant — el JS filtra por la queue del canal
        // seleccionado (global omni_queue_id null, o de esa queue). El backend
        // revalida en el envío; el frontend nunca decide la autorización final.
        $smsTemplates = \App\Models\SmsTemplate::where('is_active', true)
                                               ->orderBy('name')
                                               ->get(['id', 'name', 'body', 'omni_queue_id']);

        $voiceCapability = $this->voiceCapability($user);
        $voiceConsoleState = $this->initialVoiceConsoleState(
            $session,
            $voiceCapability,
            $user
        );
        if (!empty($voiceCapability['eligible'])) {
            app(\App\Services\Voice\AsteriskQueueMemberAvailabilityService::class)
                ->pauseForConsoleBootstrap($user);
        }

        $previewDialerEnabled = $user->tenant_id
            ? (bool) \Illuminate\Support\Facades\DB::table('voice_tenant_settings')
                ->where('tenant_id', (int) $user->tenant_id)
                ->value('preview_dialer_enabled')
            : false;

        $previewDialerConfig = [
            'enabled' => $previewDialerEnabled,
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'poll_interval' => (int) config('preview_dialer.poll_interval_ms', 5000),
        ];

        $voiceCampaigns = collect();
        $voiceScripts   = collect();
        $voiceQueues    = collect();
        if (!empty($voiceCapability['eligible'])) {
            $voiceCampaigns = $user->campaigns()
                ->where('campaigns.is_active', true)
                ->orderBy('campaigns.name')
                ->get(['campaigns.id', 'campaigns.name']);

            if ($voiceCampaigns->isNotEmpty()) {
                $voiceScripts = \App\Models\Script::withoutGlobalScopes()
                    ->whereIn('campaign_id', $voiceCampaigns->pluck('id'))
                    ->where('tenant_id', $user->tenant_id)
                    ->where('is_active', true)
                    ->whereIn('direction', ['outbound', 'both'])
                    ->orderBy('campaign_id')
                    ->orderByDesc('is_default')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'campaign_id', 'direction', 'is_default']);

                // Manual Dial: queues the agent may classify an outbound call under.
                // Only queues that (a) belong to a loaded outbound campaign,
                // (b) are active, (c) are assigned to this agent (active membership),
                // and (d) have Voice settings enabled with a technical asterisk_queue_name.
                // Preloaded client-side for the Campaign → Queue → Script cascade
                // (no AJAX endpoint). queue_name itself is never exposed here — it is
                // resolved/validated server-side at prepare time.
                $voiceQueues = $user->omniQueues()
                    ->where('omni_queues.tenant_id', $user->tenant_id)
                    ->where('omni_queues.active', true)
                    ->wherePivot('active', true)
                    ->whereIn('omni_queues.campaign_id', $voiceCampaigns->pluck('id'))
                    ->whereHas('voiceSettings', function ($q) {
                        $q->where('is_enabled', true)
                          ->whereNotNull('asterisk_queue_name')
                          ->where('asterisk_queue_name', '!=', '');
                    })
                    ->orderBy('omni_queues.name')
                    ->get()
                    ->map(fn ($q) => [
                        'id' => (int) $q->id,
                        'name' => $q->name,
                        'campaign_id' => (int) $q->campaign_id,
                        'script_id' => $q->script_id !== null ? (int) $q->script_id : '',
                    ])
                    ->values();
            }
        }

        return view('console.index', compact(
            'session',
            'pauseReasons',
            'conversations',
            'leadStatuses',
            'waTemplates',
            'smsEligibleChannels',
            'smsTemplates',
            'voiceCapability',
            'voiceConsoleState',
            'initialVoiceConversations',
            'voiceCampaigns',
            'voiceScripts',
            'voiceQueues',
            'previewDialerConfig'
        ));
    }

    private function voiceCapability(\App\Models\User $user): array
    {
        return app(
            \App\Services\Voice\AgentConsoleVoiceEligibilityService::class
        )->capability($user);
    }

    /**
     * @param array<string, bool> $voiceCapability
     * @return array<string, bool|string>
     */
    private function initialVoiceConsoleState(
        ?AgentSession $session,
        array $voiceCapability,
        \App\Models\User $user
    ): array {
        $persistedStatus = $session?->status ?? 'offline';
        $voiceEligible = (bool) ($voiceCapability['eligible'] ?? false);
        $effective = null;
        if ($voiceEligible && $user->exists) {
            $effective = app(AgentStatusService::class)->resolveEffectiveStatus($user);
        }
        $hasPreservedEffectiveVoiceState = $effective
            && in_array($effective['status'], ['in_call', 'wrap_up'], true);
        $forceOffline = $voiceEligible && $persistedStatus === 'ready' && !$hasPreservedEffectiveVoiceState;

        $status = $forceOffline ? 'offline' : $persistedStatus;
        $statusSince = $forceOffline
            ? now()
            : ($session?->status_since ?? now());

        // A page load (including the reload that follows a browser refresh) must
        // still reflect an authoritative in_call/wrap_up Voice state even when the
        // REQUESTED status was flipped to offline by the refresh-to-offline safety
        // rule — otherwise the header would falsely show Offline over an active
        // call or an unresolved Wrap-Up. Scoped to in_call/wrap_up only, matching
        // AgentStatusService::resolveActiveVoiceOccupancy()'s preservation set.
        if ($voiceEligible && !$forceOffline && $effective) {
            if (in_array($effective['status'], ['in_call', 'wrap_up'], true)) {
                $status = $effective['status'];
                $statusSince = $effective['since'];
            }
        }

        return [
            'status' => $status,
            'selector_disabled' => $voiceEligible,
            'force_offline_on_init' => $forceOffline,
            'status_since' => $statusSince->toISOString(),
        ];
    }

    private function formatLead(Lead $lead): array
    {
        return [
            'id'            => $lead->id,
            'first_name'    => $lead->first_name,
            'last_name'     => $lead->last_name,
            'email'         => $lead->email,
            'phone'         => $lead->phone,
            'address'       => $lead->address,
            'city'          => $lead->city,
            'state'         => $lead->state,
            'zip'           => $lead->zip,
            'notes'         => $lead->notes,
            'status'        => $lead->status,
            'status_label'  => $lead->status_label,
            'status_bg'     => $lead->statusModel?->bg_color ?? '#f1f5f9',
            'status_color'  => $lead->statusModel?->color ?? '#64748b',
            'campaign_id'   => $lead->campaign_id,
            'campaign_name' => $lead->campaign?->name,
            'tags'          => $lead->relationLoaded('tags')
                ? $lead->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color, 'bg_color' => $t->bg_color])->all()
                : [],
        ];
    }

    private function formatVoiceTrayLead(?Lead $lead): ?array
    {
        if (!$lead) {
            return null;
        }

        return [
            'id'          => $lead->id,
            'first_name'  => $lead->first_name,
            'last_name'   => $lead->last_name,
            'email'       => $lead->email,
            'phone'       => $lead->phone,
            'campaign_id' => $lead->campaign_id,
        ];
    }

    public function conversation(Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $conversation->load([
            'lead.statusModel',
            'lead.campaign',
            'lead.tags',
            'emailAccount',
            'messages.attachments',
            'script',                 // for selected script (step 0 of resolver)
            'omniQueue.script',       // for script resolver
            'omniQueue.campaign',     // for queue_campaign_default fallback
            'latestVoiceCall',        // for wrap-up state detection (voice only)
            'tenant',                 // for MessageTimePresenter timezone resolution
            'campaign',               // header: conversation's own campaign (NOT omniQueue.campaign — that's the queue's default, a different thing)
            'smsChannel.omniQueue', 'whatsappAccount.omniQueue', 'emailAccount.omniQueue', 'socialAccount', // header entry point + operative-campaign resolution (belongsTo — no query fires when the FK is null)
        ]);

        $lastInbound = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('sent_at')
            ->first();

        $fileService     = app(\App\Services\Storage\CrmFileService::class);
        $tenantTimezone  = \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant);

        $messages = $conversation->messages->map(function($m) use ($fileService, $tenantTimezone) {
            $mediaUrl      = null;
            $mediaFilename = null;

            if ($m->media_type) {
                $crmFile = \App\Models\CrmFile::where('message_id', $m->id)
                    ->where('status', 'active')
                    ->first();
                if ($crmFile) {
                    try {
                        $mediaUrl      = $fileService->generateTemporaryUrl($crmFile->id, null, 60);
                        $mediaFilename = $crmFile->original_filename;
                    } catch (\Throwable) {
                        // URL generation failed — media won't display but message still shows
                    }
                }
            }

            $referral = null;
            if ($m->referral_data) {
                $referral = [
                    'source_url' => $m->referral_data['source_url'] ?? null,
                    'headline'   => $m->referral_data['headline'] ?? null,
                    'body'       => $m->referral_data['body'] ?? null,
                    'image_url'  => null,
                ];

                if (!empty($m->referral_data['crm_file_id'])) {
                    try {
                        $referral['image_url'] = $fileService->generateTemporaryUrl($m->referral_data['crm_file_id'], null, 60);
                    } catch (\Throwable) {
                        // imagen no disponible — la card se muestra sin preview
                    }
                }
            }

            return [
                'id'             => $m->id,
                'direction'      => $m->direction,
                'from_email'     => $m->from_email,
                'from_name'      => $m->from_name,
                'to_email'       => $m->to_email,
                'cc_emails'      => $m->cc_emails,
                'body_html'      => $m->body_html,
                'body_text'      => $m->body_text,
                'media_type'     => $m->media_type,
                'media_url'      => $mediaUrl,
                'media_filename' => $mediaFilename,
                'referral'       => $referral,
                // sent_at_iso/time/date/day/timezone: ver MessageTimePresenter — fuente
                // única de verdad para carga inicial, refetch y realtime, calculada en
                // el timezone del tenant en el backend (nunca en el navegador del agente).
                ...\App\Helpers\MessageTimePresenter::present($m->sent_at, $tenantTimezone),
                'attachments'    => $m->attachments
                    ->filter(fn($a) => !str_contains($a->path ?? '', '/inline/'))
                    ->map(fn($a) => [
                        'id'        => $a->id,
                        'filename'  => $a->filename,
                        'mime_type' => $a->mime_type,
                        'size'      => $a->sizeForHumans(),
                    ])->values(),
            ];
        });

        $lead           = null;
        $suggestedLeads = [];
        $contactEmail   = null;

        if ($conversation->channel === 'voice'
            && $conversation->lead_id
            && $conversation->lead_list_id
            && $conversation->lead_list_row_id) {
            $expectedCampaignId = $this->previewVoiceCampaignId($conversation);
            if ($expectedCampaignId
                && $conversation->lead
                && (int) $conversation->lead->campaign_id !== (int) $expectedCampaignId) {
                Log::warning('Console conversation: cleared cross-campaign Preview Voice lead association', [
                    'conversation_id' => $conversation->id,
                    'tenant_id' => $conversation->tenant_id,
                    'lead_id' => $conversation->lead_id,
                    'lead_campaign_id' => $conversation->lead->campaign_id,
                    'expected_campaign_id' => $expectedCampaignId,
                ]);
                $conversation->update(['lead_id' => null]);
                $conversation->lead_id = null;
                $conversation->unsetRelation('lead');
            }
        }

        if ($conversation->lead) {
            $lead = $this->formatLead($conversation->lead);
        } elseif (in_array($conversation->channel, ['email', 'sms', 'whatsapp', 'chat'], true)) {
            // Auto-link por identidad (tenant + campaign operativa + teléfono/email
            // normalizado). Social (facebook/instagram) queda fuera a propósito —
            // no hay identidad social persistente en esta fase. Voice tiene su
            // propio resolver (dynamic lead row) más abajo.
            $contact = $this->extractContactForAutoLink($conversation);
            $contactEmail = $contact['email']; // sigue alimentando el prefill de compose (email)

            $operativeCampaignId = $this->resolveOperativeCampaignId($conversation);

            if ($operativeCampaignId && ($contact['phone'] || $contact['email'])) {
                $match = $this->resolveExistingLeadByContact(
                    (int) $conversation->tenant_id,
                    $operativeCampaignId,
                    $contact['phone'],
                    $contact['email'],
                );

                if ($match['status'] === 'one') {
                    $conversation->update(['lead_id' => $match['lead']->id]);
                    $lead = $this->formatLead($match['lead']);
                } elseif (in_array($match['status'], ['multiple', 'conflict'], true)) {
                    // Varios matches históricos (o conflicto phone↔email en Webchat):
                    // NO elegir, NO crear — dejar lead_id null y advertir. Sin suggested_leads.
                    Log::warning("Console auto-link: {$match['status']} lead matches — conversation left unassociated", [
                        'tenant_id'        => $conversation->tenant_id,
                        'campaign_id'      => $operativeCampaignId,
                        'conversation_id'  => $conversation->id,
                        'channel'          => $conversation->channel,
                        'normalized_phone' => $contact['phone'] ? PhoneHelper::normalizeForSearch($contact['phone']) : null,
                        'normalized_email' => $contact['email'] ? strtolower(trim($contact['email'])) : null,
                        'lead_ids'         => $match['lead_ids'],
                    ]);
                }
            }
        }

        $dynamicLeadContext = $this->dynamicLeadListContext($conversation);

        if (!$lead && $conversation->channel === 'voice' && $dynamicLeadContext['dynamic_lead_row']) {
            $resolvedLead = $this->resolveExistingLeadForPreviewVoiceConversation($conversation, $dynamicLeadContext);

            if ($resolvedLead['lead']) {
                $lead = $this->formatLead($resolvedLead['lead']);
                $dynamicLeadContext = [
                    'dynamic_lead_row' => null,
                    'lead_list' => null,
                    'dialer_campaign' => null,
                    'campaign' => null,
                ];
            } elseif ($resolvedLead['suggested_leads']) {
                $suggestedLeads = $resolvedLead['suggested_leads'];
            }
        }

        // Resolve the effective script for this conversation (1 query max via eager loading)
        $scriptData = app(ConversationScriptResolver::class)->resolve($conversation);
        $resolvedScript = $scriptData['script'];
        $scriptPayload  = $resolvedScript ? [
            'id'         => $resolvedScript->id,
            'name'       => $resolvedScript->name,
            'type'       => $resolvedScript->type,
            'direction'  => $resolvedScript->direction,
            'body'       => $resolvedScript->type === 'builder' ? $resolvedScript->body : null,
            'url'        => $resolvedScript->type === 'url'     ? $resolvedScript->url  : null,
            'url_target' => $resolvedScript->url_target,
            'url_params' => $resolvedScript->url_params ?? [],
        ] : null;

        // Header "entry point" (destino real por el que entró la interacción) —
        // solo Webchat no tiene una relación directa en conversations (el enlace
        // es inverso vía WebchatSession); para los demás canales ya están
        // eager-cargados arriba y esto no dispara ninguna query adicional.
        $webchatConfig = null;
        if ($conversation->channel === 'chat') {
            $webchatConfig = \App\Models\WebchatSession::where('conversation_id', $conversation->id)
                ->with('config')
                ->first()?->config;
        }

        $entryPointName = match ($conversation->channel) {
            'sms'                  => $conversation->smsChannel?->display_name,
            'whatsapp'             => $conversation->whatsappAccount?->display_name,
            'email'                => $conversation->emailAccount?->name,
            'facebook', 'instagram' => $conversation->socialAccount?->display_name,
            'chat'                 => $webchatConfig?->window_title,
            default                => null,
        };
        $entryPointAddress = match ($conversation->channel) {
            'sms'      => $conversation->smsChannel?->address,
            'whatsapp' => $conversation->whatsappAccount?->phone_number,
            'email'    => $conversation->emailAccount?->email,
            default    => null, // social: la "page" no tiene dirección; chat: sin dirección
        };

        return response()->json([
            'conversation' => [
                'id'         => $conversation->id,
                'subject'    => $conversation->subject,
                'from_email' => $conversation->from_email,
                'from_name'  => $conversation->from_name,
                'status'     => $conversation->status,
                'channel'    => $conversation->channel,
                'campaign_id' => $conversation->campaign_id,
                'campaign_name'        => $conversation->campaign?->name,
                'queue_name'           => $conversation->omniQueue?->name,
                'entry_point_name'     => $entryPointName,
                'entry_point_address'  => $entryPointAddress,
                'notes'      => $conversation->notes,
                'cc_emails'  => $lastInbound?->cc_emails,
                'last_from_email' => $lastInbound?->from_email,
                'last_from_name'  => $lastInbound?->from_name,
                'last_to_emails'  => collect(explode(',', $lastInbound?->to_email ?? ''))
                    ->map(fn($e) => trim($e))
                    ->filter(fn($e) => $e !== ($conversation->emailAccount?->email ?? ''))
                    ->implode(', ') ?: null,
                'has_multiple_recipients' => $lastInbound ? str_contains($lastInbound->to_email ?? '', ',') : false,
                'last_direction'       => $conversation->messages()->latest('sent_at')->value('direction'),
                'last_outbound_to'     => $conversation->messages()->where('direction', 'outbound')->latest('sent_at')->value('to_email'),
                'last_outbound_to_name' => $conversation->lead
                    ? (trim($conversation->lead->first_name . ' ' . $conversation->lead->last_name) ?: null)
                    : null,
            ],
            'messages'        => $messages,
            'lead'            => $lead,
            'dynamic_lead_row' => $dynamicLeadContext['dynamic_lead_row'],
            'lead_list'        => $dynamicLeadContext['lead_list'],
            'dialer_campaign'  => $dynamicLeadContext['dialer_campaign'],
            'campaign'         => $dynamicLeadContext['campaign'],
            'suggested_leads' => $suggestedLeads,
            'contact_email'   => $contactEmail,
            'dispositions'    => DialerDisposition::orderBy('name')->get(['id', 'name', 'bg_color', 'color', 'description', 'requires_callback_at']),
            'script'          => $scriptPayload,
            'script_source'   => $scriptData['script_source'],
            'meta'            => [
                'conversation_id' => $conversation->id,
                'lead_id'         => $conversation->lead_id,
                'campaign_id'     => $conversation->campaign_id,
                'queue_id'        => $conversation->omni_queue_id,
                'channel'         => $conversation->channel,
                'direction'       => $conversation->direction,
                'notes'           => $conversation->notes,
            ],
            // Voice-specific: present for channel=voice, null for all other channels.
            // Never includes SIP credentials or session secrets.
            'voice_call'      => $conversation->isVoice() && $conversation->latestVoiceCall
                ? [
                    'id'               => $conversation->latestVoiceCall->id,
                    'call_uuid'        => $conversation->latestVoiceCall->call_uuid,
                    'asterisk_linkedid' => $conversation->latestVoiceCall->asterisk_linkedid,
                    'asterisk_queue_name' => $conversation->latestVoiceCall->asterisk_queue_name,
                    'sip_call_id'      => $conversation->latestVoiceCall->sip_call_id,
                    'direction'        => $conversation->latestVoiceCall->direction,
                    'call_state'       => $conversation->latestVoiceCall->call_state,
                    'remote_number'    => $conversation->latestVoiceCall->remote_number,
                    'ani_normalized'   => $conversation->latestVoiceCall->ani_normalized,
                    'dnis_normalized'  => $conversation->latestVoiceCall->dnis_normalized,
                    'ani_display'      => $conversation->latestVoiceCall->ani_display,
                    'dnis_display'     => $conversation->latestVoiceCall->dnis_display,
                    'remote_display_name' => $conversation->latestVoiceCall->remote_display_name,
                    'ringing_at'       => $conversation->latestVoiceCall->ringing_at?->toISOString(),
                    'answered_at'      => $conversation->latestVoiceCall->answered_at?->toISOString(),
                    'ended_at'         => $conversation->latestVoiceCall->ended_at?->toISOString(),
                    'duration_seconds' => $conversation->latestVoiceCall->duration_seconds,
                    'talk_seconds'     => $conversation->latestVoiceCall->talk_seconds,
                    'is_terminal'      => $conversation->latestVoiceCall->isTerminal(),
                ]
                : null,
            'is_voice_wrap_up' => $conversation->isVoiceWrapUpPending(),
        ]);
    }

    /**
     * @return array{dynamic_lead_row: ?array, lead_list: ?array, dialer_campaign: ?array, campaign: ?array}
     */
    private function previewVoiceCampaignId(Conversation $conversation): ?int
    {
        if ($conversation->campaign_id) {
            return (int) $conversation->campaign_id;
        }

        if (!$conversation->dialer_campaign_id) {
            return null;
        }

        $campaignId = DialerCampaign::withoutGlobalScopes()
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->where('id', (int) $conversation->dialer_campaign_id)
            ->value('campaign_id');

        return $campaignId ? (int) $campaignId : null;
    }

    private function dynamicLeadListContext(Conversation $conversation): array
    {
        $empty = [
            'dynamic_lead_row' => null,
            'lead_list' => null,
            'dialer_campaign' => null,
            'campaign' => null,
        ];

        if ($conversation->lead_id
            || !$conversation->lead_list_id
            || !$conversation->lead_list_row_id) {
            return $empty;
        }

        $leadList = LeadList::withoutGlobalScopes()
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->find((int) $conversation->lead_list_id);

        if (!$leadList || !$leadList->table_name) {
            return $empty;
        }

        $tableName = (string) $leadList->table_name;
        if (!preg_match('/^dl_[1-9][0-9]*_leads$/', $tableName)
            || !Schema::hasTable($tableName)) {
            return $empty;
        }

        $row = DB::table($tableName)
            ->where('id', (int) $conversation->lead_list_row_id)
            ->first();

        if (!$row) {
            return $empty;
        }

        $customFields = [];
        $rawCustomFields = $row->custom_fields ?? null;
        if (is_string($rawCustomFields) && trim($rawCustomFields) !== '') {
            $decoded = json_decode($rawCustomFields, true);
            if (is_array($decoded)) {
                $customFields = $decoded;
            }
        }

        $dialerCampaign = null;
        $crmCampaignId = $conversation->campaign_id ?: null;
        if ($conversation->dialer_campaign_id) {
            $campaign = DialerCampaign::withoutGlobalScopes()
                ->where('tenant_id', (int) $conversation->tenant_id)
                ->find((int) $conversation->dialer_campaign_id);

            if ($campaign) {
                $crmCampaignId = $crmCampaignId ?: $campaign->campaign_id;
                $dialerCampaign = [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'campaign_id' => $campaign->campaign_id,
                    'script_id' => $campaign->script_id,
                ];
            }
        }

        $crmCampaign = null;
        if ($crmCampaignId) {
            $campaign = \App\Models\Campaign::withoutGlobalScopes()
                ->where('tenant_id', (int) $conversation->tenant_id)
                ->find((int) $crmCampaignId);

            if ($campaign) {
                $crmCampaign = [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'status' => $campaign->is_active ? 'active' : 'inactive',
                ];
            }
        }

        return [
            'dynamic_lead_row' => [
                'id' => (int) $row->id,
                'phone' => $row->phone ?? null,
                'status' => $row->status ?? null,
                'called_count' => isset($row->called_count) ? (int) $row->called_count : null,
                'callback_at' => $row->callback_at ?? null,
                'last_disposition' => $row->last_disposition ?? null,
                'custom_fields' => $customFields,
                'raw_custom_fields' => $rawCustomFields,
            ],
            'lead_list' => [
                'id' => $leadList->id,
                'name' => $leadList->name,
                'table_name' => $leadList->table_name,
                'log_table_name' => $leadList->log_table_name,
            ],
            'dialer_campaign' => $dialerCampaign,
            'campaign' => $crmCampaign,
        ];
    }

    /**
     * @param  array{dynamic_lead_row: ?array, lead_list: ?array, dialer_campaign: ?array, campaign: ?array}  $dynamicLeadContext
     * @return array{lead: ?Lead, suggested_leads: array<int, array<string, mixed>>}
     */
    private function resolveExistingLeadForPreviewVoiceConversation(Conversation $conversation, array $dynamicLeadContext): array
    {
        $empty = ['lead' => null, 'suggested_leads' => []];

        if ($conversation->lead_id || $conversation->channel !== 'voice') {
            return $empty;
        }

        $dynamicRow = $dynamicLeadContext['dynamic_lead_row'] ?? null;
        if (!$dynamicRow) {
            return $empty;
        }

        $campaignId = (int) ($conversation->campaign_id
            ?: ($dynamicLeadContext['dialer_campaign']['campaign_id'] ?? 0));

        if ($campaignId <= 0) {
            return $empty;
        }

        $customFields = is_array($dynamicRow['custom_fields'] ?? null)
            ? $dynamicRow['custom_fields']
            : [];

        $primaryPhone = PhoneHelper::normalizeForSearch((string) ($dynamicRow['phone'] ?? ''));
        $fallbackPhones = collect([
            $conversation->latestVoiceCall?->remote_number,
            $conversation->from_email,
            $conversation->from_name,
            $customFields['Phone'] ?? null,
        ])
            ->map(fn ($value) => PhoneHelper::normalizeForSearch((string) ($value ?? '')))
            ->filter()
            ->reject(fn (string $phone) => $primaryPhone !== '' && $phone === $primaryPhone)
            ->unique()
            ->values();
        $emailCandidate = $this->blankToNull($customFields['Email'] ?? null);

        if ($primaryPhone === '' && $fallbackPhones->isEmpty() && !$emailCandidate) {
            return $empty;
        }

        $suggested = fn ($matches): array => $matches->map(fn (Lead $lead) => [
            'id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'campaign_name' => $lead->campaign?->name,
        ])->values()->toArray();

        $associate = function (Lead $lead) use ($conversation): array {
            $conversation->update(['lead_id' => $lead->id]);
            $conversation->setRelation('lead', $lead);

            return ['lead' => $lead, 'suggested_leads' => []];
        };

        $phoneMatches = function ($phones) use ($conversation, $campaignId) {
            $phones = collect($phones)->filter()->unique()->values();
            if ($phones->isEmpty()) {
                return collect();
            }

            $lastFourDigits = $phones
                ->map(fn (string $phone) => strlen($phone) >= 4 ? substr($phone, -4) : $phone)
                ->filter()
                ->unique()
                ->values();

            return Lead::with(['statusModel', 'campaign'])
                ->where('tenant_id', (int) $conversation->tenant_id)
                ->where('campaign_id', $campaignId)
                ->where(function ($query) use ($lastFourDigits): void {
                    foreach ($lastFourDigits as $index => $digits) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}('phone', 'like', '%' . $digits);
                    }
                })
                ->limit(25)
                ->get()
                ->filter(function (Lead $candidate) use ($phones): bool {
                $candidatePhone = PhoneHelper::normalizeForSearch((string) ($candidate->phone ?? ''));
                    return $candidatePhone !== '' && $phones->contains($candidatePhone);
                })
                ->unique('id')
                ->values();
        };

        $emailMatches = function (string $email) use ($conversation, $campaignId) {
            return Lead::with(['statusModel', 'campaign'])
                ->where('tenant_id', (int) $conversation->tenant_id)
                ->where('campaign_id', $campaignId)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->limit(25)
                ->get()
                ->unique('id')
                ->values();
        };

        // Tier 1: the dynamic lead-list row phone is the primary dialed phone.
        // A unique primary-phone match wins even when another lead shares the same email.
        if ($primaryPhone !== '') {
            $matches = $phoneMatches([$primaryPhone]);
            if ($matches->count() === 1) {
                return $associate($matches->first());
            }
            if ($matches->count() > 1) {
                return ['lead' => null, 'suggested_leads' => $suggested($matches)];
            }
        }

        // Tier 2: only if primary phone found no match, try secondary/fallback phones.
        if ($fallbackPhones->isNotEmpty()) {
            $matches = $phoneMatches($fallbackPhones);
            if ($matches->count() === 1) {
                return $associate($matches->first());
            }
            if ($matches->count() > 1) {
                return ['lead' => null, 'suggested_leads' => $suggested($matches)];
            }
        }

        // Tier 3: email is lowest priority and only runs when no phone tier matched.
        if ($emailCandidate) {
            $matches = $emailMatches($emailCandidate);
            if ($matches->count() === 1) {
                return $associate($matches->first());
            }
            if ($matches->count() > 1) {
                return ['lead' => null, 'suggested_leads' => $suggested($matches)];
            }
        }

        return $empty;
    }

    /**
     * Campaign operativa de una conversación, resuelta SIEMPRE en backend
     * (nunca del frontend). Prioridad: campaign propia de la conversación →
     * campaign de la queue del canal de entrada. Devuelve null si no hay una
     * campaign válida del mismo tenant (en cuyo caso NO se autoasocia).
     */
    private function resolveOperativeCampaignId(Conversation $conversation): ?int
    {
        $tenantId = (int) $conversation->tenant_id;
        $candidate = null;

        if ($conversation->campaign_id) {
            $candidate = (int) $conversation->campaign_id;
        } else {
            $candidate = match ($conversation->channel) {
                'sms'      => $conversation->smsChannel?->omniQueue?->campaign_id,
                'whatsapp' => $conversation->whatsappAccount?->omniQueue?->campaign_id,
                'email'    => $conversation->emailAccount?->omniQueue?->campaign_id,
                'chat'     => $this->webchatOperativeCampaignId($conversation),
                default    => null,
            };
            $candidate = $candidate ? (int) $candidate : null;
        }

        if (!$candidate) {
            return null;
        }

        $valid = \App\Models\Campaign::where('id', $candidate)
            ->where('tenant_id', $tenantId)
            ->exists();

        return $valid ? $candidate : null;
    }

    /** Webchat no tiene FK directo en conversations — se resuelve vía WebchatSession. */
    private function webchatOperativeCampaignId(Conversation $conversation): ?int
    {
        $session = \App\Models\WebchatSession::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('conversation_id', $conversation->id)
            ->with('config.omniQueue')
            ->first();

        return $session?->config?->omniQueue?->campaign_id;
    }

    /**
     * Extrae (phone, email) del contacto remoto según el canal, para el auto-link.
     * Nunca usa nombre/from_name ni el participant_id opaco de social.
     *
     * @return array{phone: ?string, email: ?string}
     */
    private function extractContactForAutoLink(Conversation $conversation): array
    {
        $phone = null;
        $email = null;

        switch ($conversation->channel) {
            case 'sms':
            case 'whatsapp':
                $phone = $conversation->external_id ?: $conversation->from_email;
                break;

            case 'email':
                $hasInbound = $conversation->messages()->where('direction', 'inbound')->exists();
                $email = $hasInbound
                    ? $conversation->from_email
                    : $conversation->messages()
                        ->where('direction', 'outbound')
                        ->orderBy('sent_at', 'asc')
                        ->value('to_email');
                break;

            case 'chat':
                // Webchat guarda el teléfono en from_email; el email (si se
                // capturó en el lead-form) vive en la WebchatSession.
                $phone   = $conversation->from_email;
                $session = \App\Models\WebchatSession::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                    ->where('conversation_id', $conversation->id)
                    ->first();
                $email   = $session?->email;
                if (!$phone) {
                    $phone = $session?->phone;
                }
                break;
        }

        return ['phone' => $phone, 'email' => $email];
    }

    /**
     * Resolver de identidad de Lead por (tenant + campaign + teléfono/email
     * normalizado). Fuente única de verdad para el auto-link (al abrir/enviar)
     * y para el guard de creación. NUNCA usa source ni nombre.
     *
     * Normalización: teléfono vía PhoneHelper::normalizeForSearch(); email vía
     * trim + lowercase. Cuando se pasan ambas señales (Webchat), si el teléfono
     * apunta a un Lead y el email a otro distinto → 'conflict'.
     *
     * @return array{status: 'none'|'one'|'multiple'|'conflict', lead: ?Lead, lead_ids: int[]}
     */
    private function resolveExistingLeadByContact(int $tenantId, ?int $campaignId, ?string $phone, ?string $email): array
    {
        $none = ['status' => 'none', 'lead' => null, 'lead_ids' => []];

        if (!$campaignId) {
            return $none;
        }

        $normPhone = $phone !== null ? PhoneHelper::normalizeForSearch($phone) : '';
        $normEmail = $email !== null ? strtolower(trim($email)) : '';

        $phoneLeads = collect();
        if ($normPhone !== '') {
            $last4 = strlen($normPhone) >= 4 ? substr($normPhone, -4) : $normPhone;
            $phoneLeads = Lead::with(['statusModel', 'campaign', 'tags'])
                ->where('tenant_id', $tenantId)
                ->where('campaign_id', $campaignId)
                ->where('phone', 'like', '%' . $last4)
                ->limit(50)
                ->get()
                ->filter(fn (Lead $l) => PhoneHelper::normalizeForSearch((string) ($l->phone ?? '')) === $normPhone)
                ->unique('id')
                ->values();
        }

        $emailLeads = collect();
        if ($normEmail !== '') {
            $emailLeads = Lead::with(['statusModel', 'campaign', 'tags'])
                ->where('tenant_id', $tenantId)
                ->where('campaign_id', $campaignId)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$normEmail])
                ->limit(50)
                ->get()
                ->unique('id')
                ->values();
        }

        $bothSignals = $normPhone !== '' && $normEmail !== '';

        if ($bothSignals) {
            if ($phoneLeads->count() > 1 || $emailLeads->count() > 1) {
                return [
                    'status'   => 'multiple',
                    'lead'     => null,
                    'lead_ids' => $phoneLeads->pluck('id')->merge($emailLeads->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ];
            }

            $pLead = $phoneLeads->first();
            $eLead = $emailLeads->first();

            if ($pLead && $eLead && (int) $pLead->id !== (int) $eLead->id) {
                return ['status' => 'conflict', 'lead' => null, 'lead_ids' => [(int) $pLead->id, (int) $eLead->id]];
            }

            $lead = $pLead ?? $eLead;
            return $lead
                ? ['status' => 'one', 'lead' => $lead, 'lead_ids' => [(int) $lead->id]]
                : $none;
        }

        $set = $normPhone !== '' ? $phoneLeads : $emailLeads;

        if ($set->count() === 1) {
            return ['status' => 'one', 'lead' => $set->first(), 'lead_ids' => [(int) $set->first()->id]];
        }
        if ($set->count() > 1) {
            return ['status' => 'multiple', 'lead' => null, 'lead_ids' => $set->pluck('id')->map(fn ($id) => (int) $id)->values()->all()];
        }

        return $none;
    }

    public function updateDynamicLeadRowField(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $validated = $request->validate([
            'field' => ['required', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($conversation->lead_id
            || !$conversation->lead_list_id
            || !$conversation->lead_list_row_id) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_row_unavailable',
            ], 422);
        }

        $leadList = LeadList::withoutGlobalScopes()
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->find((int) $conversation->lead_list_id);

        $tableName = (string) ($leadList?->table_name ?? '');
        if (!$leadList
            || !preg_match('/^dl_[1-9][0-9]*_leads$/', $tableName)
            || !Schema::hasTable($tableName)) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_table_missing',
            ], 404);
        }

        $row = DB::table($tableName)
            ->where('id', (int) $conversation->lead_list_row_id)
            ->first();

        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_row_missing',
            ], 404);
        }

        $field = trim((string) $validated['field']);
        $value = trim((string) ($validated['value'] ?? ''));
        $touch = Schema::hasColumn($tableName, 'updated_at')
            ? ['updated_at' => now()]
            : [];

        if ($field === '__phone') {
            DB::table($tableName)
                ->where('id', (int) $conversation->lead_list_row_id)
                ->update(array_merge(['phone' => $value], $touch));
        } else {
            $customFields = [];
            $rawCustomFields = $row->custom_fields ?? null;
            if (is_string($rawCustomFields) && trim($rawCustomFields) !== '') {
                $decoded = json_decode($rawCustomFields, true);
                if (is_array($decoded)) {
                    $customFields = $decoded;
                }
            }

            $customFields[$field] = $value;

            DB::table($tableName)
                ->where('id', (int) $conversation->lead_list_row_id)
                ->update(array_merge([
                    'custom_fields' => json_encode($customFields, JSON_UNESCAPED_UNICODE),
                ], $touch));
        }

        $context = $this->dynamicLeadListContext($conversation->fresh());

        return response()->json([
            'ok' => true,
            'dynamic_lead_row' => $context['dynamic_lead_row'],
            'lead_list' => $context['lead_list'],
            'dialer_campaign' => $context['dialer_campaign'],
            'campaign' => $context['campaign'],
        ]);
    }

    public function createLeadFromDynamicLeadRow(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $validated = $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
        ]);

        if ($conversation->lead_id
            || !$conversation->lead_list_id
            || !$conversation->lead_list_row_id) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_row_unavailable',
            ], 422);
        }

        $leadList = LeadList::withoutGlobalScopes()
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->find((int) $conversation->lead_list_id);

        $tableName = (string) ($leadList?->table_name ?? '');
        if (!$leadList
            || !preg_match('/^dl_[1-9][0-9]*_leads$/', $tableName)
            || !Schema::hasTable($tableName)) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_table_missing',
            ], 404);
        }

        $row = DB::table($tableName)
            ->where('id', (int) $conversation->lead_list_row_id)
            ->first();

        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'dynamic_lead_row_missing',
            ], 404);
        }

        $customFields = [];
        $rawCustomFields = $row->custom_fields ?? null;
        if (is_string($rawCustomFields) && trim($rawCustomFields) !== '') {
            $decoded = json_decode($rawCustomFields, true);
            if (is_array($decoded)) {
                $customFields = $decoded;
            }
        }

        $campaignId = $validated['campaign_id']
            ?? $conversation->campaign_id
            ?? null;

        if (!$campaignId && $conversation->dialer_campaign_id) {
            $campaignId = DialerCampaign::withoutGlobalScopes()
                ->where('tenant_id', (int) $conversation->tenant_id)
                ->where('id', (int) $conversation->dialer_campaign_id)
                ->value('campaign_id');
        }

        if (!$campaignId) {
            throw ValidationException::withMessages([
                'campaign_id' => 'A campaign is required to create a lead from this list row.',
            ]);
        }

        $campaignExists = DB::table('campaigns')
            ->where('tenant_id', (int) $conversation->tenant_id)
            ->where('id', (int) $campaignId)
            ->exists();

        if (!$campaignExists) {
            throw ValidationException::withMessages([
                'campaign_id' => 'The selected campaign is invalid.',
            ]);
        }

        $firstName = trim((string) ($customFields['First Name'] ?? ''));
        $lastName = trim((string) ($customFields['Last Name'] ?? ''));
        if (($firstName === '' || $lastName === '') && !empty($customFields['Full Name'])) {
            $parts = preg_split('/\s+/', trim((string) $customFields['Full Name']), 2);
            if ($firstName === '') {
                $firstName = $parts[0] ?? '';
            }
            if ($lastName === '') {
                $lastName = $parts[1] ?? '';
            }
        }

        $primaryPhone = trim((string) ($row->phone ?? ''));
        if ($primaryPhone === '') {
            $primaryPhone = trim((string) ($customFields['Phone'] ?? ''));
        }

        $defaultStatus = LeadStatus::where('is_default', true)->value('slug') ?? 'new';

        $lead = Lead::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->blankToNull($customFields['Email'] ?? null),
            'phone' => $primaryPhone,
            'address' => $this->blankToNull($customFields['Street Address'] ?? null),
            'city' => $this->blankToNull($customFields['City'] ?? null),
            'state' => $this->blankToNull($customFields['State'] ?? null),
            'zip' => $this->blankToNull($customFields['Zip'] ?? null),
            'campaign_id' => (int) $campaignId,
            'status' => $defaultStatus,
            'source' => 'Preview Dialer',
            'assigned_to' => Auth::id(),
        ]);

        $conversation->update(['lead_id' => $lead->id]);
        $lead->load('statusModel', 'campaign', 'tags');

        return response()->json([
            'ok' => true,
            'lead' => $this->formatLead($lead),
        ]);
    }

    public function reply(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);
        $request->validate([
            'body'          => 'required|string',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        $cleanBody    = $this->cleanHtmlBody($request->body);
        $inlineImages = $this->extractInlineImages($cleanBody);
        $account      = $conversation->emailAccount;

        try {
            [$mailer, $messageId] = $this->buildMailer($account);

            $lastMessage = $conversation->messages()->latest('sent_at')->first();
            $lastMessage->load('attachments');

            $lastInbound = $conversation->messages()
                ->where('direction', 'inbound')->latest('sent_at')->first();

            $toEmail = $lastInbound
                ? $lastInbound->from_email
                : $conversation->messages()->where('direction', 'outbound')->latest('sent_at')->value('to_email');

            $fullBody = $cleanBody . $this->buildQuote($lastMessage);

            $email = (new Email())
                ->to($toEmail)
                ->subject('Re: ' . $conversation->subject)
                ->text(strip_tags($fullBody));

            $this->applyEmailIdentity($email, $account);
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            $allInlineAtts = $this->embedAllInlineImages($email, $fullBody);
            $this->embedNewInlineImages($email, $inlineImages);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $email->attach(
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        $file->getMimeType()
                    );
                }
            }

            $email->html($fullBody);
            $email->text(strip_tags($fullBody));

            if ($lastInbound?->message_id) {
                $formattedId = '<' . trim($lastInbound->message_id, '<>') . '>';
                $email->getHeaders()->addTextHeader('In-Reply-To', $formattedId);
                $email->getHeaders()->addTextHeader('References', $formattedId);
            }

            $mailer->send($email);
            $sentAt = now();

            $this->saveInlineImages($inlineImages);
            $bodyForDb = $this->revertCidsToUrls($fullBody, $allInlineAtts);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_name'       => $account->name,
                'from_email'      => $account->email,
                'to_email'        => $toEmail,
                'body_html'       => $bodyForDb,
                'body_text'       => strip_tags($bodyForDb),
                'message_id'      => $messageId,
                'sent_by'         => Auth::id(),
                'sent_at'         => $sentAt,
            ]);

            $this->saveAttachments($message, $request);
            $this->createInlineAttachments($message, $inlineImages, $bodyForDb);
            $message->update(['body_html' => $bodyForDb, 'body_text' => strip_tags($bodyForDb)]);

            return response()->json([
                'ok'         => true,
                'message_id' => $message->id,
                'from_email' => $account->email,
                'to_email'   => $toEmail,
                'body_html'  => $bodyForDb,
                'body_text'  => strip_tags($bodyForDb),
                ...\App\Helpers\MessageTimePresenter::present(
                    $sentAt,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
                'attachments' => $message->attachments()
                    ->where('path', 'not like', '%/inline/%')
                    ->get()
                    ->map(fn($a) => [
                        'id'        => $a->id,
                        'filename'  => $a->filename,
                        'mime_type' => $a->mime_type,
                        'size'      => $a->sizeForHumans(),
                    ])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Console reply error: " . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function replyAll(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $request->validate([
            'body'          => 'required|string',
            'cc_emails'     => 'required|string',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        $cleanBody    = $this->cleanHtmlBody($request->body);
        $inlineImages = $this->extractInlineImages($cleanBody);
        $account      = $conversation->emailAccount;

        try {
            [$mailer, $messageId] = $this->buildMailer($account);

            $lastInbound = $conversation->messages()
                ->where('direction', 'inbound')->latest('sent_at')->first();

            $lastMessage = $conversation->messages()->latest('sent_at')->first();
            $lastMessage->load('attachments');

            $toEmail = $lastInbound?->from_email ?? $conversation->from_email;

            $ccList = array_filter(array_map('trim', explode(',', $request->cc_emails)));
            $ccList = array_filter($ccList, fn($e) => $e !== $account->email);
            $toEmailsOfLastInbound = array_filter(
                array_map('trim', explode(',', $lastInbound?->to_email ?? '')),
                fn($e) => $e !== $account->email
            );
            $ccList = array_unique(array_merge($ccList, $toEmailsOfLastInbound));

            $fullBody = $cleanBody . $this->buildQuote($lastMessage);

            $email = (new Email())
                ->to($toEmail)
                ->subject('Re: ' . $conversation->subject)
                ->text(strip_tags($fullBody));

            $this->applyEmailIdentity($email, $account);
            if (!empty($ccList)) $email->cc(...$ccList);
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            $allInlineAtts = $this->embedAllInlineImages($email, $fullBody);
            $this->embedNewInlineImages($email, $inlineImages);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $email->attach(
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        $file->getMimeType()
                    );
                }
            }

            $email->html($fullBody);
            $email->text(strip_tags($fullBody));

            if ($lastInbound?->message_id) {
                $formattedId = '<' . trim($lastInbound->message_id, '<>') . '>';
                $email->getHeaders()->addTextHeader('In-Reply-To', $formattedId);
                $email->getHeaders()->addTextHeader('References', $formattedId);
            }

            $mailer->send($email);
            $sentAt = now();

            $this->saveInlineImages($inlineImages);
            $bodyForDb = $this->revertCidsToUrls($fullBody, $allInlineAtts);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_email'      => $account->email,
                'from_name'       => $account->name,
                'to_email'        => $toEmail,
                'cc_emails'       => implode(', ', $ccList),
                'body_html'       => $bodyForDb,
                'body_text'       => strip_tags($bodyForDb),
                'message_id'      => $messageId,
                'sent_by'         => Auth::id(),
                'sent_at'         => $sentAt,
            ]);

            $this->saveAttachments($message, $request);
            $this->createInlineAttachments($message, $inlineImages, $bodyForDb);
            $message->update(['body_html' => $bodyForDb, 'body_text' => strip_tags($bodyForDb)]);

            return response()->json([
                'ok'         => true,
                'message_id' => $message->id,
                'from_email' => $account->email,
                'to_email'   => $toEmail,
                'cc_emails'  => implode(', ', $ccList),
                'body_html'  => $bodyForDb,
                'body_text'  => strip_tags($bodyForDb),
                ...\App\Helpers\MessageTimePresenter::present(
                    $sentAt,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
                'attachments' => $message->attachments()
                    ->where('path', 'not like', '%/inline/%')
                    ->get()
                    ->map(fn($a) => [
                        'id'        => $a->id,
                        'filename'  => $a->filename,
                        'mime_type' => $a->mime_type,
                        'size'      => $a->sizeForHumans(),
                    ])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Console replyAll error: " . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function forward(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);
        $request->validate([
            'body'          => 'required|string',
            'forward_to'    => 'required|string',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        $cleanBody    = $this->cleanHtmlBody($request->body);
        $inlineImages = $this->extractInlineImages($cleanBody);
        $account      = $conversation->emailAccount;

        try {
            [$mailer, $messageId] = $this->buildMailer($account);
            $recipients = array_filter(array_map('trim', explode(',', $request->forward_to)));

            $lastMessage = $conversation->messages()->latest('sent_at')->first();
            $lastMessage->load('attachments');

            $fullBody = $cleanBody . $this->buildQuote($lastMessage);

            $email = (new Email())
                ->to(...$recipients)
                ->subject('Fwd: ' . $conversation->subject)
                ->text(strip_tags($fullBody));

            $this->applyEmailIdentity($email, $account);
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            foreach ($lastMessage->attachments as $att) {
                if (str_contains($att->path ?? '', '/inline/')) continue;

                if ($att->crm_file_id && $att->crmFile) {
                    try {
                        $disk    = app(\App\Services\Storage\TenantStorageManager::class)->resolveDiskForTenant($att->tenant_id);
                        $content = $disk->get($att->crmFile->object_key);
                        $email->attach($content, $att->filename, $att->mime_type);
                    } catch (\Throwable $e) {
                        Log::warning("Forward: R2 attachment skipped for att #{$att->id} — " . $e->getMessage());
                    }
                } elseif ($att->path && Storage::disk('local')->exists($att->path)) {
                    $email->attach(Storage::disk('local')->get($att->path), $att->filename, $att->mime_type);
                }
            }

            $allInlineAtts = $this->embedAllInlineImages($email, $fullBody);
            $this->embedNewInlineImages($email, $inlineImages);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $email->attach(
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        $file->getMimeType()
                    );
                }
            }

            $email->html($fullBody);
            $email->text(strip_tags($fullBody));

            $mailer->send($email);
            $sentAt = now();

            $this->saveInlineImages($inlineImages);
            $bodyForDb = $this->revertCidsToUrls($fullBody, $allInlineAtts);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_email'      => $account->email,
                'from_name'       => $account->name,
                'to_email'        => implode(', ', $recipients),
                'cc_emails'       => null,
                'body_html'       => $bodyForDb,
                'body_text'       => strip_tags($bodyForDb),
                'message_id'      => $messageId,
                'sent_by'         => Auth::id(),
                'sent_at'         => $sentAt,
            ]);

            $this->saveAttachments($message, $request);
            $this->createInlineAttachments($message, $inlineImages, $bodyForDb);
            $message->update(['body_html' => $bodyForDb, 'body_text' => strip_tags($bodyForDb)]);

            return response()->json([
                'ok'         => true,
                'message_id' => $message->id,
                'from_email' => $account->email,
                'to_email'   => $request->forward_to,
                'body_html'  => $bodyForDb,
                'body_text'  => strip_tags($bodyForDb),
                ...\App\Helpers\MessageTimePresenter::present(
                    $sentAt,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
                'attachments' => $message->attachments()
                    ->where('path', 'not like', '%/inline/%')
                    ->get()
                    ->map(fn($a) => [
                        'id'        => $a->id,
                        'filename'  => $a->filename,
                        'mime_type' => $a->mime_type,
                        'size'      => $a->sizeForHumans(),
                    ])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error("Console forward error: " . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function compose(Request $request)
    {
        $request->validate([
            'to'             => 'required|email',
            'subject'        => 'required|string',
            'body'           => 'required|string',
            'cc'             => 'nullable|string',
            'bcc'            => 'nullable|string',
            'attachments.*'  => 'nullable|file|max:10240',
            'disposition_id' => 'nullable|exists:dialer_dispositions,id',
        ]);

        $user     = Auth::user();
        $accounts = $this->getAgentEmailAccounts();
        $account  = $accounts->isNotEmpty()
            ? \App\Models\EmailAccount::find($accounts->first()['id'])
            : \App\Models\EmailAccount::where('active', true)->first();

        if (!$account) {
            return response()->json(['ok' => false, 'error' => 'No active email account found.'], 422);
        }

        try {
            [$mailer, $messageId] = $this->buildMailer($account);

            $cleanBody    = $this->cleanHtmlBody($request->body);
            $inlineImages = $this->extractInlineImages($cleanBody);

            $email = (new Email())
                ->to($request->to)
                ->subject($request->subject)
                ->text(strip_tags($cleanBody));

            $this->applyEmailIdentity($email, $account);

            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            // CC
            if ($request->filled('cc')) {
                $ccList = array_filter(array_map('trim', explode(',', $request->cc)));
                if (!empty($ccList)) $email->cc(...$ccList);
            }

            // BCC
            if ($request->filled('bcc')) {
                $bccList = array_filter(array_map('trim', explode(',', $request->bcc)));
                if (!empty($bccList)) $email->bcc(...$bccList);
            }

            // Adjuntos normales al email
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $email->attach(
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        $file->getMimeType()
                    );
                }
            }

            $this->embedNewInlineImages($email, $inlineImages);
            $email->html($cleanBody);
            $email->text(strip_tags($cleanBody));

            $mailer->send($email);

            $sentAt = now();

            // lead_id explícito (viene del form) tiene prioridad sobre auto-detect por email
            $explicitLeadId = $request->input('lead_id');
            $lead = $explicitLeadId
                ? Lead::find($explicitLeadId)
                : Lead::where('email', $request->to)->first();

            $conversation = Conversation::create([
                'channel'          => 'email',
                'email_account_id' => $account->id,
                'omni_queue_id'    => $account->omni_queue_id,
                'lead_id'          => $lead?->id,
                'assigned_to'      => $user->id,
                'from_email'       => $account->email,
                'from_name'        => $lead?->full_name ?? null,
                'subject'          => $request->subject,
                'status'           => 'active',
                'wait_since'       => now(),
                'assigned_at'      => now(),
            ]);

            $this->saveInlineImages($inlineImages);
            $bodyForDb = $cleanBody;

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'from_email'      => $account->email,
                'from_name'       => $account->name,
                'to_email'        => $request->to,
                'cc_emails'       => $request->filled('cc') ? $request->cc : null,
                'body_html'       => $bodyForDb,
                'body_text'       => strip_tags($bodyForDb),
                'message_id'      => $messageId,
                'sent_by'         => $user->id,
                'sent_at'         => $sentAt,
            ]);

            $this->saveAttachments($message, $request);
            $this->createInlineAttachments($message, $inlineImages, $bodyForDb);
            $message->update(['body_html' => $bodyForDb, 'body_text' => strip_tags($bodyForDb)]);

            // Disposición opcional (desde la vista del lead)
            if ($request->filled('disposition_id')) {
                $disposition = DialerDisposition::find($request->disposition_id);
                if ($disposition) {
                    $resolvedAt = now();
                    $conversation->update([
                        'status'      => 'resolved',
                        'disposition' => $disposition->name,
                        'resolved_at' => $resolvedAt,
                        'direction'   => 'outbound',
                    ]);
                    try {
                        $preview = \Str::limit(strip_tags($cleanBody), 100);
                        \App\Models\ConversationReport::create([
                            'tenant_id'             => $conversation->tenant_id,
                            'conversation_id'       => $conversation->id,
                            'agent_id'              => $user->id,
                            'agent_name'            => $user->name,
                            'lead_id'               => $conversation->lead_id,
                            'lead_name'             => $lead?->full_name,
                            'contact_email'         => $request->to,
                            'channel'               => 'email',
                            'direction'             => 'outbound',
                            'subject'               => $request->subject,
                            'first_message_preview' => $preview,
                            'disposition'           => $disposition->name,
                            'message_count'         => 1,
                            'inbound_message_count'  => 0,
                            'outbound_message_count' => 1,
                            'duration_seconds'      => 0,
                            'wait_since'            => $conversation->wait_since,
                            'resolved_at'           => $resolvedAt,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Compose disposition snapshot error: ' . $e->getMessage());
                    }
                }
            }

            return response()->json([
                'ok'             => true,
                'conversation_id' => $conversation->id,
                'email_accounts' => $this->getAgentEmailAccounts(),
                'multi_account'  => $this->getAgentEmailAccounts()->count() > 1,
            ]);

        } catch (\Throwable $e) {
            Log::error("Console compose error: " . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function unread(\Illuminate\Http\Request $request)
    {
        $user    = Auth::user();
        $channel = $request->query('channel');

        $query = Conversation::with(['lead', 'messages' => function ($q) {
            $q->latest()->limit(1);
        }])
            ->where('assigned_to', $user->id)
            ->where('status', 'active')
            ->whereNull('read_at')
            ->orderBy('wait_since', 'desc');

        if ($channel === 'chat') {
            $query->whereIn('channel', ['whatsapp', 'chat', 'facebook', 'instagram', 'sms']);
        } else {
            $query->where('channel', 'email');
        }

        $conversations = $query->get();

        return response()->json([
            'count'         => $conversations->count(),
            'conversations' => $conversations->map(fn($c) => [
                'id'          => $c->id,
                'subject'     => $c->subject,
                'from_name'   => $c->from_name,
                'from_email'  => $c->from_email,
                'external_id' => $c->external_id,
                'wait_since'  => ($c->wait_since ?? $c->created_at)?->diffForHumans(),
                'channel'     => $c->channel,
                'lead_id'     => $c->lead_id,
            ]),
        ]);
    }

    public function markRead(Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);
        $conversation->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function disposition(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);
        $request->validate([
            'disposition_id' => 'required|exists:dialer_dispositions,id',
            'notes'          => 'nullable|string|max:500',
            'callback_at'    => 'nullable|date',
            'after_disposition_status'           => 'nullable|in:ready,paused,offline',
            'after_disposition_pause_reason_id'  => 'nullable|integer',
        ]);

        // After Disposition Status — Voice-only. Validated up front, before any
        // mutation, so an invalid selection never partially applies (the field
        // is silently ignored for non-Voice channels — it does not exist in
        // their disposition flow).
        $afterStatus = $conversation->channel === 'voice'
            ? $request->input('after_disposition_status')
            : null;
        $afterPauseReasonId = null;

        if ($afterStatus === 'paused') {
            $afterPauseReasonId = $request->input('after_disposition_pause_reason_id');
            if (!$afterPauseReasonId) {
                throw ValidationException::withMessages([
                    'after_disposition_pause_reason_id' => 'Pause reason is required for After Disposition Status: Paused.',
                ]);
            }
            // PauseReason uses BelongsToTenant — this lookup is already scoped to
            // the authenticated agent's tenant, so a cross-tenant id resolves to
            // null exactly like an inactive/nonexistent one.
            if (!PauseReason::active()->find($afterPauseReasonId)) {
                throw ValidationException::withMessages([
                    'after_disposition_pause_reason_id' => 'Invalid or inactive pause reason.',
                ]);
            }
        }

        $disposition = DialerDisposition::find($request->disposition_id);
        $resolvedAt  = now();
        $callbackAt = $request->filled('callback_at')
            ? Carbon::parse((string) $request->input('callback_at'))
            : null;

        $dispositionSync = app(PreviewDialerLeadDispositionSyncService::class);
        $requiresPreviewCallbackAt = $conversation->channel === 'voice'
            && $conversation->lead_list_id
            && $conversation->lead_list_row_id
            && $dispositionSync->requiresCallbackAt($disposition);

        if ($requiresPreviewCallbackAt && !$callbackAt) {
            throw ValidationException::withMessages([
                'callback_at' => 'Callback date/time is required for this disposition.',
            ]);
        }

        $firstMsg = null;
        if ($conversation->channel === 'chat') {
            $direction = 'inbound';
        } elseif ($conversation->channel === 'voice') {
            // Voice conversations have no Message records.
            // The direction was set authoritatively by VoiceConversationService at
            // call creation time and must not be overwritten by a message fallback.
            $direction = $conversation->direction ?? 'inbound';
        } else {
            $firstMsg  = $conversation->messages()->reorder()->orderBy('sent_at', 'asc')
                             ->where('from_email', '!=', 'bot@internal')
                             ->first();
            $direction = $firstMsg?->direction ?? 'inbound';
        }

        // Preserve existing notes (e.g. an AI handoff reason) when the agent
        // doesn't type anything new in the disposition modal — same final
        // value is used below for both conversations.notes and the report.
        $finalNotes = $request->filled('notes') ? $request->notes : $conversation->notes;

        $conversation->update([
            'status'          => 'resolved',
            'disposition'     => $disposition->name,
            'resolved_at'     => $resolvedAt,
            'resolved_source' => 'human',
            'ai_bot_active'   => false,
            'direction'       => $direction,
            'notes'           => $finalNotes,
        ]);

        $dispositionSync->sync(
            $conversation->fresh(),
            $disposition,
            (int) Auth::id(),
            $callbackAt,
        );

        if ($conversation->channel === 'voice') {
            app(TelephonyCallWrapupService::class)->finishFromConversation(
                $conversation->fresh(),
                $disposition->name,
                $resolvedAt
            );
        }

        // Phase 1E-B3 / After Disposition Status: a successful Voice disposition
        // ends effective Wrap-Up and resyncs queue_members.paused to the agent's
        // requested status. Never applies to Email/Chat/WhatsApp/social —
        // disposition semantics for those channels are unchanged.
        $voiceStatusPayload = null;
        if ($conversation->channel === 'voice') {
            $statusService = app(AgentStatusService::class);

            if ($afterStatus !== null) {
                // Stage-only: does not touch AgentStatusLog/queue_members yet —
                // exitVoiceWrapUpAfterDisposition() below (already gated by
                // hasOtherPendingVoiceWrapUp()) is what actually applies it, and
                // only once the LAST pending Wrap-Up is resolved.
                $statusService->stageRequestedStatusForVoiceDisposition(Auth::user(), $afterStatus, $afterPauseReasonId);
            }

            try {
                $statusService->exitVoiceWrapUpAfterDisposition(
                    Auth::user(),
                    $request->input('browser_tab_id')
                );
            } catch (\Throwable $e) {
                Log::warning('[AgentEffectiveStatus] exit wrap-up after disposition failed', [
                    'tenant_id' => $conversation->tenant_id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $effective = $statusService->resolveEffectiveStatus(Auth::user());
            $agentSession = AgentSession::withoutGlobalScopes()
                ->where('user_id', Auth::id())
                ->with('pauseReason')
                ->first();

            $voiceStatusPayload = [
                'effective_status'  => $effective['status'],
                'requested_status'  => $agentSession?->status,
                'status_since'      => $effective['since']?->toISOString(),
                'pause_reason_id'    => $agentSession?->pause_reason_id,
                'pause_reason_name'  => $agentSession?->pauseReason?->name,
                'pause_reason_color' => $agentSession?->pauseReason?->color,
                // Derived from the business rule (only 'ready' unpauses), not a
                // fresh queue_members read — avoids adding a new Asterisk query
                // to the disposition response.
                'queue_paused' => $effective['status'] !== 'ready',
                'remaining_voice_wrap_up_count' => $statusService->countPendingVoiceWrapUps(Auth::user()),
            ];
        }

        // ── Snapshot en conversation_reports ──
        try {
            $conversation->load('lead');
            $isOutbound   = $direction === 'outbound';
            $contactEmail = $isOutbound
                ? $firstMsg?->to_email
                : $conversation->from_email;

            $leadName = $conversation->lead
                ? trim($conversation->lead->first_name . ' ' . $conversation->lead->last_name)
                : null;

            // Duration = wait_since → resolved_at for ALL channels including Voice.
            // For Voice: wait_since ≈ ringing_at → resolved_at = ring + talk + wrap-up
            // This is the total handle time — consistent with Email, WhatsApp, Chat.
            $durationSeconds = $conversation->wait_since
                ? (int) $conversation->wait_since->diffInSeconds($resolvedAt)
                : null;

            $messageCount = $conversation->messages()->count();

            $preview = $firstMsg
                ? \Str::limit(strip_tags($firstMsg->body_text ?? $firstMsg->body_html ?? ''), 100)
                : null;

            \App\Models\ConversationReport::updateOrCreate([
                'conversation_id'       => $conversation->id,
            ], [
                'tenant_id'             => $conversation->tenant_id,
                'agent_id'              => Auth::id(),
                'agent_name'            => Auth::user()->name,
                'lead_id'               => $conversation->lead_id,
                'lead_name'             => $leadName,
                'contact_email'         => $contactEmail,
                'channel'               => $conversation->channel,
                'direction'             => $isOutbound ? 'outbound' : 'inbound',
                'subject'               => $conversation->subject,
                'first_message_preview' => $preview,
                'disposition'           => $disposition->name,
                'resolved_source'       => 'human',
                'message_count'          => $messageCount,
                'inbound_message_count'  => $conversation->messages()->where('direction', 'inbound')->count(),
                'outbound_message_count' => $conversation->messages()->where('direction', 'outbound')->count(),
                'duration_seconds'      => $durationSeconds,
                'wait_since'            => $conversation->wait_since,
                'resolved_at'           => $resolvedAt,
                'notes'                 => $finalNotes,
            ]);
        } catch (\Throwable $e) {
            Log::error('ConversationReport snapshot error: ' . $e->getMessage());
        }

        if ($conversation->channel === 'chat') {
            $session = \App\Models\WebchatSession::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->where('conversation_id', $conversation->id)
                ->first();
            if ($session) {
                try {
                    $pusher = new \Pusher\Pusher(
                        config('broadcasting.connections.pusher.key'),
                        config('broadcasting.connections.pusher.secret'),
                        config('broadcasting.connections.pusher.app_id'),
                        ['cluster' => config('broadcasting.connections.pusher.options.cluster')]
                    );
                    $pusher->trigger('webchat.' . $session->session_token, 'conversation-ended', []);
                } catch (\Throwable $e) {
                    Log::warning('disposition chat Pusher error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(array_merge(['ok' => true], $voiceStatusPayload ?? []));
    }

    public function markAllRead()
    {
        Conversation::where('assigned_to', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function sendWaTemplate(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'account_id'    => 'required|integer',
            'phone'         => 'required|string',
            'template_name' => 'required|string',
            'lead_id'       => 'nullable|integer',
        ]);

        $account = \App\Models\WhatsappAccount::withoutGlobalScopes()
            ->where('is_active', true)
            ->findOrFail($request->account_id);

        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');

        $template = \App\Models\WhatsappTemplate::withoutGlobalScopes()
            ->where('name', $request->template_name)
            ->first();

        $langCode = $template?->language ?? 'es';

        $response = \Illuminate\Support\Facades\Http::withToken($account->access_token)
            ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $request->phone,
                'type'              => 'template',
                'template'          => [
                    'name'     => $request->template_name,
                    'language' => ['code' => $langCode],
                ],
            ]);

        if ($response->failed()) {
            return response()->json(['error' => 'WhatsApp API error: ' . $response->body()], 500);
        }

        $wamid = $response->json('messages.0.id');

        $lead         = $request->lead_id ? \App\Models\Lead::withoutGlobalScopes()->find($request->lead_id) : null;
        $displayPhone = \App\Helpers\PhoneHelper::formatDisplay($request->phone);
        $leadName     = $lead ? trim($lead->first_name . ' ' . $lead->last_name) : null;

        $targetTenantId = $lead?->tenant_id ?? (Auth::user()->tenant_id ?? $account->tenant_id);

        $conversation = \App\Models\Conversation::create([
            'tenant_id'           => $targetTenantId,
            'channel'             => 'whatsapp',
            'whatsapp_account_id' => $account->id,
            'omni_queue_id'       => $account->omni_queue_id,
            'lead_id'             => $lead?->id,
            'external_id'         => $request->phone,
            'from_email'          => $displayPhone,
            'from_name'           => $leadName ?? $displayPhone,
            'subject'             => $displayPhone,
            'status'              => 'active',
            'assigned_to'         => Auth::id(),
            'assigned_at'         => now(),
            'wait_since'          => now(),
        ]);

        $bodyText = $template?->body_preview ?? $request->template_name;

        \App\Models\Message::create([
            'tenant_id'       => $targetTenantId,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->phone_number,
            'from_name'       => Auth::user()->name,
            'to_email'        => $request->phone,
            'body_text'       => $bodyText,
            'body_html'       => null,
            'message_id'      => $wamid,
            'sent_by'         => Auth::id(),
            'sent_at'         => now(),
        ]);

        return response()->json(['ok' => true, 'conversation_id' => $conversation->id]);
    }

    public function sendWhatsapp(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'body'            => 'required|string|max:4096',
        ]);

        $conversation = Conversation::findOrFail($request->conversation_id);

        abort_unless($conversation->channel === 'whatsapp', 400, 'Not a WhatsApp conversation');
        abort_unless(
            $conversation->assigned_to === Auth::id() || Auth::user()->isAdmin(),
            403
        );

        $account = $conversation->whatsappAccount;
        abort_unless($account, 404, 'No WhatsApp account linked to this conversation');

        $apiBase = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v21.0');

        $response = \Illuminate\Support\Facades\Http::withToken($account->access_token)
            ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $conversation->external_id,
                'type'              => 'text',
                'text'              => ['body' => $request->body],
            ]);

        if ($response->failed()) {
            return response()->json(['error' => 'WhatsApp API error: ' . $response->body()], 500);
        }

        $wamid = $response->json('messages.0.id');

        $message = \App\Models\Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->phone_number,
            'from_name'       => Auth::user()->name,
            'to_email'        => $conversation->external_id,
            'body_text'       => $request->body,
            'body_html'       => null,
            'message_id'      => $wamid,
            'sent_by'         => Auth::id(),
            'sent_at'         => now(),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => [
                'id'         => $message->id,
                'direction'  => 'outbound',
                'from_name'  => Auth::user()->name,
                'from_email' => $account->phone_number,
                'body_text'  => $request->body,
                'body_html'  => null,
                ...\App\Helpers\MessageTimePresenter::present(
                    $message->sent_at,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
            ],
        ]);
    }

    /**
     * Enviar mensaje a conversación de Facebook/Instagram vía Zernio.
     * Espejo de sendWhatsapp — misma forma de respuesta para el JS.
     */
    public function sendSocial(\Illuminate\Http\Request $request, \App\Services\ZernioService $zernio): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'body'            => 'required|string|max:4096',
        ]);

        $conversation = Conversation::findOrFail($request->conversation_id);

        abort_unless($conversation->isSocial(), 400, 'Not a social conversation');
        abort_unless(
            $conversation->assigned_to === Auth::id() || Auth::user()->isAdmin(),
            403
        );

        $account = $conversation->socialAccount;
        abort_unless($account, 404, 'No social account linked to this conversation');

        try {
            $result = $zernio->sendMessage(
                $conversation->external_id,
                $account->zernio_account_id,
                $request->body
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('sendSocial: Zernio error — ' . $e->getMessage());
            return response()->json(['error' => 'Could not send the message. The messaging window may have expired.'], 500);
        }

        $message = \App\Models\Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->username ?? $account->display_name,
            'from_name'       => Auth::user()->name,
            'to_email'        => $conversation->from_email,
            'body_text'       => $request->body,
            'body_html'       => null,
            'message_id'      => $result['message_id'],
            'sent_by'         => Auth::id(),
            'sent_at'         => now(),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => [
                'id'         => $message->id,
                'direction'  => 'outbound',
                'from_name'  => Auth::user()->name,
                'from_email' => $account->username ?? $account->display_name,
                'body_text'  => $request->body,
                'body_html'  => null,
                ...\App\Helpers\MessageTimePresenter::present(
                    $message->sent_at,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
            ],
        ]);
    }

    /**
     * Números SMS elegibles para QuickActions del agente. Un canal es elegible
     * cuando: pertenece al tenant (global scope) + está activo + soporta p2p
     * (outbound) + tiene queue + la queue está activa + el AGENTE es miembro
     * activo de esa queue (pivot omni_queue_user.active) + la queue tiene una
     * campaign válida del mismo tenant. Un canal sin queue nunca es elegible.
     *
     * @return \Illuminate\Support\Collection<int,\App\Models\SmsChannel>
     */
    private function eligibleSmsChannelsForAgent(\App\Models\User $user): \Illuminate\Support\Collection
    {
        $agentQueueIds = $user->omniQueues()
            ->where('omni_queues.tenant_id', $user->tenant_id)
            ->where('omni_queues.active', true)
            ->wherePivot('active', true)
            ->whereNotNull('omni_queues.campaign_id')
            ->pluck('omni_queues.id');

        if ($agentQueueIds->isEmpty()) {
            return collect();
        }

        return \App\Models\SmsChannel::with(['omniQueue.campaign'])
            ->where('is_active', true)
            ->whereIn('omni_queue_id', $agentQueueIds)
            ->orderBy('display_name')
            ->get()
            ->filter(fn ($ch) => $ch->supportsP2p()
                && $ch->omniQueue
                && $ch->omniQueue->campaign_id
                && (int) ($ch->omniQueue->campaign?->tenant_id ?? 0) === (int) $user->tenant_id)
            ->values();
    }

    /**
     * SMS P2P (Quick Actions) — un solo destinatario, el agente que envía conserva
     * ownership. Toda la lógica de opt-out/envío/creación de conversación vive en
     * SmsService. Campaign y Queue se DERIVAN del canal en backend — nunca se
     * confía en campaign_id/queue_id/tenant_id del frontend.
     */
    public function sendSmsP2p(\Illuminate\Http\Request $request, \App\Services\Sms\SmsService $smsService): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'channel_id'          => 'required|integer',
            'country_code'        => 'required|string',
            'destination_number'  => 'required|string',
            'body'                => 'required|string|max:1600',
            'lead_id'             => 'nullable|integer',
            'template_id'         => 'nullable|integer',
        ]);

        $user = Auth::user();

        // El canal debe ser elegible para ESTE agente. channel_id es lo único
        // que se acepta del frontend, y aun así se valida contra la lista real.
        $channel = $this->eligibleSmsChannelsForAgent($user)
            ->firstWhere('id', (int) $request->channel_id);

        if (!$channel) {
            return response()->json(['error' => 'This SMS number is not available for your active queues.'], 403);
        }

        // Campaign operativa derivada del canal (queue → campaign) — nunca del request.
        $campaignId = (int) $channel->omniQueue->campaign_id;

        try {
            $destination = \App\Helpers\PhoneHelper::composeInternationalDestination(
                $request->country_code,
                $request->destination_number,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Template opcional: debe pertenecer al tenant (global scope) + estar
        // activo + ser global (omni_queue_id null) o de la queue del canal.
        $templateId = $request->integer('template_id') ?: null;
        if ($templateId) {
            $templateOk = \App\Models\SmsTemplate::where('id', $templateId)
                ->where('is_active', true)
                ->where(function ($q) use ($channel) {
                    $q->whereNull('omni_queue_id')
                      ->orWhere('omni_queue_id', $channel->omni_queue_id);
                })
                ->exists();

            if (!$templateOk) {
                return response()->json(['error' => 'The selected template is not available for this number.'], 422);
            }
        }

        // Lead: si el agente eligió uno, validar que pertenece a la campaign
        // derivada (mismo tenant). Si no eligió, intentar auto-link por
        // (tenant + campaign + teléfono normalizado del destino).
        $leadId = $request->integer('lead_id') ?: null;

        if ($leadId) {
            $leadOk = Lead::where('id', $leadId)
                ->where('tenant_id', $user->tenant_id)
                ->where('campaign_id', $campaignId)
                ->exists();

            if (!$leadOk) {
                return response()->json(['error' => "The selected lead does not belong to this number's campaign."], 422);
            }
        } else {
            $match = $this->resolveExistingLeadByContact((int) $user->tenant_id, $campaignId, $destination, null);

            if ($match['status'] === 'one') {
                $leadId = (int) $match['lead']->id;
            } elseif (in_array($match['status'], ['multiple', 'conflict'], true)) {
                Log::warning("Console sendSmsP2p: {$match['status']} existing leads — SMS sent without lead association", [
                    'tenant_id'        => $user->tenant_id,
                    'campaign_id'      => $campaignId,
                    'normalized_phone' => PhoneHelper::normalizeForSearch($destination),
                    'lead_ids'         => $match['lead_ids'],
                ]);
                $leadId = null;
            }
        }

        try {
            $conversation = $smsService->sendP2p(
                channel: $channel,
                to: $destination,
                body: $request->body,
                agent: $user,
                leadId: $leadId,
                campaignId: $campaignId,
                templateId: $templateId,
            );
        } catch (\App\Services\Sms\Exceptions\SmsOptOutException $e) {
            return response()->json(['error' => 'This number has opted out of SMS (STOP).'], 422);
        } catch (\App\Services\Sms\Exceptions\SmsSendException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'conversation_id' => $conversation->id]);
    }

    /**
     * Respuesta del agente dentro de una conversación SMS ya existente — espejo de
     * sendWhatsapp(). El origen se resuelve del canal ligado a la conversación, nunca
     * del request.
     */
    public function sendSmsReply(\Illuminate\Http\Request $request, \App\Services\Sms\SmsService $smsService): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'conversation_id' => 'required|integer',
            'body'            => 'required|string|max:1600',
        ]);

        $conversation = Conversation::findOrFail($request->conversation_id);

        abort_unless($conversation->channel === 'sms', 400, 'Not an SMS conversation');
        abort_unless(
            $conversation->assigned_to === Auth::id() || Auth::user()->isAdmin(),
            403
        );

        try {
            $message = $smsService->sendReply($conversation, $request->body, Auth::user());
        } catch (\App\Services\Sms\Exceptions\SmsOptOutException $e) {
            return response()->json(['error' => 'This number has opted out of SMS (STOP).'], 422);
        } catch (\App\Services\Sms\Exceptions\SmsSendException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => [
                'id'         => $message->id,
                'direction'  => 'outbound',
                'from_name'  => Auth::user()->name,
                'from_email' => $message->from_email,
                'body_text'  => $message->body_text,
                'body_html'  => null,
                ...\App\Helpers\MessageTimePresenter::present(
                    $message->sent_at,
                    \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
                ),
            ],
        ]);
    }

    public function availableAgents()
    {
        $tenantId = Auth::user()->tenant_id;

        $agents = \App\Models\AgentSession::where('status', 'ready')
            ->where('user_id', '!=', Auth::id())
            ->whereHas('user', fn($q) => $q->where('tenant_id', $tenantId))
            ->with('user:id,name,tenant_id')
            ->get()
            ->map(fn($s) => ['id' => $s->user_id, 'name' => $s->user->name ?? ''])
            ->values();

        return response()->json(['agents' => $agents]);
    }

    public function transfer(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $tenantId = Auth::user()->tenant_id;
        $request->validate([
            'agent_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $agentReady = \App\Models\AgentSession::where('user_id', $request->agent_id)
            ->where('status', 'ready')
            ->exists();

        if (!$agentReady) {
            return response()->json(['error' => 'Agent is not available'], 422);
        }

        $conversation->update([
            'transferred_from' => Auth::id(),
            'assigned_to'      => $request->agent_id,
            'assigned_at'      => now(),
            'read_at'          => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function myConversations()
    {
        $conversations = Conversation::with([
            'messages' => fn($q) => $q->latest()->limit(1),
            'latestVoiceCall', // for voice wrap-up state in tray
            'lead',
        ])
            ->where('assigned_to', Auth::id())
            ->whereIn('status', ['active', 'pending'])
            ->orderBy('wait_since', 'desc')
            ->get();

        return response()->json($conversations->sortByDesc('wait_since')->values()->map(fn($c) => [
            'id'              => $c->id,
            'subject'         => $c->subject,
            'from_name'       => $c->from_name,
            'from_email'      => $c->from_email,
            'wait_since'      => ($c->wait_since ?? $c->created_at)?->diffForHumans(null, true, true),
            'last_message_at' => $c->messages->first()?->sent_at?->toISOString(),
            'channel'         => $c->channel,
            'lead_id'         => $c->lead_id,
            'lead_name'       => $c->lead ? trim($c->lead->first_name . ' ' . $c->lead->last_name) : null,
            'lead'            => $c->isVoice() ? $this->formatVoiceTrayLead($c->lead) : null,
            'status'          => $c->status,
            'ai_bot_active'   => (bool) $c->ai_bot_active,
            'read_at'         => $c->read_at,
            'has_unread'      => $c->read_at === null
                ? true
                : ($c->isVoice()
                    ? false // voice conversations don't have unread messages
                    : $c->messages()
                        ->where('direction', 'inbound')
                        ->where('sent_at', '>', $c->read_at)
                        ->exists()),
            'preview' => $c->isVoice()
                ? ($c->latestVoiceCall ? ucfirst($c->latestVoiceCall->call_state) : 'Voice')
                : ($c->messages->first()?->body_text
                    ? \Str::limit(strip_tags($c->messages->first()->body_text), 50)
                    : ''),
            // Voice-specific fields for tray rendering
            'voice_call_state' => $c->isVoice() ? $c->latestVoiceCall?->call_state : null,
            'voice_direction'  => $c->isVoice() ? $c->direction : null,
            'remote_phone'     => $c->isVoice() ? $c->from_email : null,
            // Reuses the canonical Conversation::isVoiceWrapUpPending() predicate — the
            // frontend must never infer Wrap-Up from call_state/status alone (see
            // AgentConsoleVoiceAdapter.shouldHydrateVoiceConversation()).
            'is_voice_wrap_up' => $c->isVoice() ? $c->isVoiceWrapUpPending() : false,
        ]));
    }

    public function messageHtml(Message $message)
    {
        abort_unless($message->conversation->assigned_to === Auth::id(), 403);

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    margin: 12px 16px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                    font-size: 13px;
                    line-height: 1.6;
                    color: #334155;
                    word-break: break-word;
                    background: transparent;
                }
                img { max-width: 100%; height: auto; }
                a { color: #6366f1; }
                blockquote {
                    border-left: 3px solid #e2e8f0;
                    margin: 8px 0;
                    padding-left: 12px;
                    color: #94a3b8;
                }
                @media (prefers-color-scheme: dark) {
                    body { color: #334155 !important; background: #ffffff !important; }
                }
            </style>
        </head>
        <body>{$message->body_html}</body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function createLead(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $validated = $request->validate([
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'phone'       => 'required|string|max:30',
            'email'       => 'nullable|email|max:100',
            'campaign_id' => 'required|exists:campaigns,id',
        ]);

        $tenantId   = (int) $conversation->tenant_id;
        $campaignId = (int) $validated['campaign_id'];

        // La campaign debe pertenecer al tenant de la conversación — un exists
        // global no garantiza el tenant correcto.
        $campaignOk = \App\Models\Campaign::where('id', $campaignId)
            ->where('tenant_id', $tenantId)
            ->exists();
        if (!$campaignOk) {
            $msg = 'The selected campaign is invalid for this conversation.';
            return response()->json(['ok' => false, 'message' => $msg, 'error' => $msg], 422);
        }

        $defaultStatus = LeadStatus::where('is_default', true)->value('slug') ?? 'new';

        $sourceMap = [
            'email' => 'Email',
            'chat'  => 'Webchat',
            'voice' => 'Voice',
            'phone' => 'Voice',
            'sms'   => 'SMS',
        ];
        $source = $sourceMap[$conversation->channel] ?? ucfirst($conversation->channel);

        // Guard de duplicados dentro de una transacción: re-buscar por
        // (tenant + campaign + phone/email normalizado) justo antes de crear.
        return DB::transaction(function () use ($request, $conversation, $tenantId, $campaignId, $defaultStatus, $source) {
            $match = $this->resolveExistingLeadByContact($tenantId, $campaignId, $request->phone, $request->email);

            if ($match['status'] === 'one') {
                // Ya existe exactamente uno → vincularlo, NO crear otro.
                $conversation->update(['lead_id' => $match['lead']->id]);
                $lead = $match['lead'];
                $lead->load('statusModel', 'campaign', 'tags');
                return response()->json(['ok' => true, 'linked_existing' => true, 'lead' => $this->formatLead($lead)]);
            }

            if (in_array($match['status'], ['multiple', 'conflict'], true)) {
                Log::warning("Console createLead: {$match['status']} existing leads — creation blocked", [
                    'tenant_id'       => $tenantId,
                    'campaign_id'     => $campaignId,
                    'conversation_id' => $conversation->id,
                    'lead_ids'        => $match['lead_ids'],
                ]);
                // JSON unificado (message + error con el mismo texto) para que el
                // frontend pinte el mensaje real. Sin cambios en la lógica de dedup.
                $msg = $match['status'] === 'conflict'
                    ? 'This phone and email match different existing leads in this campaign. A new lead was not created.'
                    : 'Duplicate leads already exist in this campaign. A new lead was not created.';
                return response()->json([
                    'ok'       => false,
                    'message'  => $msg,
                    'error'    => $msg,
                    'lead_ids' => $match['lead_ids'],
                ], 422);
            }

            // Sin match → crear normalmente.
            $lead = Lead::create([
                'first_name'  => $request->first_name,
                'last_name'   => $request->last_name,
                'phone'       => $request->phone,
                'email'       => $request->email,
                'campaign_id' => $campaignId,
                'status'      => $defaultStatus,
                'source'      => $source,
                'assigned_to' => Auth::id(),
            ]);

            $conversation->update(['lead_id' => $lead->id]);
            $lead->load('statusModel', 'campaign', 'tags');

            return response()->json(['ok' => true, 'linked_existing' => false, 'lead' => $this->formatLead($lead)]);
        });
    }

    public function associateLead(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->assigned_to === Auth::id(), 403);

        $request->validate(['lead_id' => 'required|exists:leads,id']);

        $tenantId = (int) $conversation->tenant_id;
        $conversation->loadMissing(['smsChannel.omniQueue', 'whatsappAccount.omniQueue', 'emailAccount.omniQueue']);
        $operativeCampaignId = $this->resolveOperativeCampaignId($conversation);

        $query = Lead::with(['statusModel', 'campaign', 'tags'])
            ->where('tenant_id', $tenantId);

        if ($operativeCampaignId) {
            // Solo permitir asociar un Lead de la MISMA campaign operativa.
            $query->where('campaign_id', $operativeCampaignId);
        } else {
            // Sin campaign operativa, mantener el guard previo (campañas del agente)
            // para no permitir asociar cualquier lead del tenant.
            $query->whereIn('campaign_id', Auth::user()->campaigns()->pluck('campaigns.id'));
        }

        $lead = $query->find($request->lead_id);

        if (!$lead) {
            return response()->json(['ok' => false, 'error' => "That lead is not in this conversation's campaign."], 422);
        }

        // No se cambia la campaign del Lead — solo se asocia a la conversación.
        $conversation->update(['lead_id' => $lead->id]);

        return response()->json(['ok' => true, 'lead' => $this->formatLead($lead)]);
    }

    public function searchLeads(Request $request)
    {
        $q       = trim($request->query('q', ''));
        $isAdmin = Auth::user()->isAdmin();
        $campaignId = $request->integer('campaign_id') ?: null;

        $leadsQuery = Lead::with('campaign:id,name')
            ->where(function($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
            });

        if ($campaignId) {
            $leadsQuery->where('campaign_id', $campaignId);
        }

        if (!$isAdmin) {
            $agentCampaignIds = Auth::user()->campaigns()->pluck('campaigns.id');
            $leadsQuery->whereIn('campaign_id', $agentCampaignIds);
        }

        $leads = $leadsQuery->limit(20)->get()
            ->map(fn($l) => [
                'id'            => $l->id,
                'first_name'    => $l->first_name,
                'last_name'     => $l->last_name,
                'email'         => $l->email,
                'phone'         => $l->phone,
                'campaign_id'   => $l->campaign_id,
                'campaign_name' => $l->campaign?->name,
            ]);

        return response()->json(['leads' => $leads]);
    }

    public function leadHistory(Lead $lead)
    {
        $conversations = \App\Models\ConversationReport::where('lead_id', $lead->id)
            ->orderBy('resolved_at', 'desc')
            ->get()
            ->map(fn($c) => [
                'type'      => $c->channel,
                // For Voice, encode direction in icon_type so the JS iconMap can show
                // inbound vs outbound phone icons without a separate direction lookup.
                'icon_type' => $c->channel === 'voice'
                    ? ('voice_' . ($c->direction ?? 'inbound'))
                    : $c->channel,
                'title'     => $c->subject ?? '(no subject)',
                'subtitle'  => $c->disposition ?? '',
                'direction' => $c->direction ?? 'inbound',
                'date'      => $c->resolved_at?->diffForHumans(),
                '_sort'     => $c->resolved_at?->timestamp ?? 0,
            ]);

        $calls = \App\Models\Call::where('lead_id', $lead->id)
            ->orderBy('called_at', 'desc')
            ->get()
            ->map(fn($c) => [
                'type'      => 'call',
                'icon_type' => $c->completed ? 'call_answered' : 'call_missed',
                'title'     => $c->disposition ?? 'Call',
                'subtitle'  => $c->duration ? gmdate('i:s', $c->duration) : '—',
                'date'      => $c->called_at ? \Carbon\Carbon::parse($c->called_at)->diffForHumans() : '—',
                '_sort'     => $c->called_at ? \Carbon\Carbon::parse($c->called_at)->timestamp : 0,
            ]);

        $history = $conversations->concat($calls)
            ->sortByDesc('_sort')
            ->map(fn($i) => collect($i)->except('_sort')->toArray())
            ->values();

        return response()->json(['history' => $history]);
    }

    public function saveLeadNotes(Request $request, Lead $lead)
    {
        $request->validate(['notes' => 'nullable|string']);
        $lead->update(['notes' => $request->notes]);
        return response()->json(['ok' => true]);
    }

    public function leadDetail(Lead $lead): \Illuminate\Http\JsonResponse
    {
        $campaignId = request()->integer('campaign_id') ?: null;
        if ($campaignId && (int) $lead->campaign_id !== (int) $campaignId) {
            abort(404);
        }

        $lead->load(['tags', 'campaign', 'statusModel']);
        $status = $lead->statusModel;
        return response()->json([
            'id'            => $lead->id,
            'first_name'    => $lead->first_name,
            'last_name'     => $lead->last_name,
            'email'         => $lead->email,
            'phone'         => $lead->phone,
            'phone_2'       => $lead->phone_2,
            'address'       => $lead->address,
            'city'          => $lead->city,
            'state'         => $lead->state,
            'zip'           => $lead->zip,
            'country'       => $lead->country,
            'status'        => $lead->status,
            'source'        => $lead->source,
            'value'         => $lead->value !== null ? (string) $lead->value : null,
            'status_label'  => $status?->name ?? ucwords(str_replace('_', ' ', $lead->status ?? '')),
            'status_bg'     => $status?->bg_color ?? '#f1f5f9',
            'status_color'  => $status?->color ?? '#64748b',
            'campaign_id'   => $lead->campaign_id,
            'campaign_name' => $lead->campaign?->name,
            'notes'         => $lead->notes,
            'tags'          => $lead->tags->map(fn ($t) => [
                'id'       => $t->id,
                'name'     => $t->name,
                'bg_color' => $t->bg_color,
                'color'    => $t->color,
            ])->values()->toArray(),
        ]);
    }

    public function updateLeadField(Request $request, Lead $lead)
    {
        $allowed = ['first_name', 'last_name', 'email', 'phone', 'address', 'city', 'state', 'zip', 'status', 'campaign_id'];
        $request->validate([
            'field' => 'required|in:' . implode(',', $allowed),
            'value' => 'nullable|string|max:255',
        ]);

        $lead->update([$request->field => $request->value ?: null]);
        return response()->json(['ok' => true]);
    }

    public function myCampaigns()
    {
        $campaigns = Auth::user()->campaigns()
            ->where('is_active', true)
            ->get(['campaigns.id', 'campaigns.name']);

        return response()->json(['campaigns' => $campaigns]);
    }

    public function composeAccounts()
    {
        return response()->json([
            'email_accounts' => $this->getAgentEmailAccounts(),
            'multi_account'  => $this->getAgentEmailAccounts()->count() > 1,
        ]);
    }

    public function sendChat(Request $request, int $conversationId): \Illuminate\Http\JsonResponse
    {
        $conversation = Conversation::findOrFail($conversationId);

        abort_unless(
            $conversation->assigned_to === Auth::id() || Auth::user()->isAdmin(),
            403
        );
        abort_unless($conversation->channel === 'chat', 422, 'Not a chat conversation');

        $request->validate(['body_text' => 'required|string|max:4096']);

        $msg = Message::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_name'       => Auth::user()->name,
            'from_email'      => Auth::user()->email,
            'to_email'        => $conversation->from_email,
            'body_text'       => $request->body_text,
            'sent_at'         => now(),
        ]);

        $session = \App\Models\WebchatSession::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($session) {
            try {
                $pusher = new \Pusher\Pusher(
                    config('broadcasting.connections.pusher.key'),
                    config('broadcasting.connections.pusher.secret'),
                    config('broadcasting.connections.pusher.app_id'),
                    ['cluster' => config('broadcasting.connections.pusher.options.cluster')]
                );
                $pusher->trigger('webchat.' . $session->session_token, 'agent-message', [
                    'id'           => $msg->id,
                    'body_text'    => $msg->body_text,
                    'from_name'    => Auth::user()->name,
                    'from_initial' => mb_substr(Auth::user()->name, 0, 1),
                    'sent_at'      => $msg->sent_at->toISOString(),
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('sendChat Pusher error: ' . $e->getMessage());
            }
        }

        return response()->json([
            'id'           => $msg->id,
            'body_text'    => $msg->body_text,
            'from_name'    => Auth::user()->name,
            'from_initial' => mb_substr(Auth::user()->name, 0, 1),
            ...\App\Helpers\MessageTimePresenter::present(
                $msg->sent_at,
                \App\Helpers\MessageTimePresenter::resolveTenantTimezone($conversation->tenant)
            ),
            'direction'    => 'outbound',
        ]);
    }

    /**
     * Genera URL temporal (60 min) para un crm_file desde la consola del agente.
     * Valida que el archivo pertenezca al tenant del agente autenticado.
     */
    public function crmFileUrl(\App\Models\CrmFile $file)
    {
        abort_unless(
            Auth::user()->is_superadmin || $file->tenant_id === Auth::user()->tenant_id,
            403
        );

        $url = app(\App\Services\Storage\CrmFileService::class)
            ->generateTemporaryUrl($file->id, Auth::id(), 60);

        return response()->json(['url' => $url]);
    }
}
