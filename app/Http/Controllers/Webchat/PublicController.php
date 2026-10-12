<?php
declare(strict_types=1);

namespace App\Http\Controllers\Webchat;

use App\Helpers\BusinessHoursHelper;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Models\WebchatConfig;
use App\Models\WebchatSession;
use App\Services\Automation\ConversationAutomationForwardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicController extends Controller
{
    public function __construct(
        private readonly ConversationAutomationForwardingService $automationForwardingService,
    ) {}

    private function validateOrigin(WebchatConfig $config): void
    {
        if (empty($config->allowed_origin)) return;

        $origin  = request()->header('Origin') ?? request()->header('Referer') ?? '';
        $allowed = rtrim($config->allowed_origin, '/');

        if (str_starts_with($allowed, '*.')) {
            $base  = substr($allowed, 2);
            $valid = str_contains($origin, $base);
        } else {
            $valid = str_starts_with($origin, $allowed);
        }

        if (!$valid) {
            abort(response()->json(['error' => 'Origin not allowed'], 403)
                ->withHeaders(['Access-Control-Allow-Origin' => '*']));
        }
    }

    private function corsHeaders(): array
    {
        return ['Access-Control-Allow-Origin' => '*'];
    }

    public function config(string $publicToken)
    {
        $config = WebchatConfig::withoutGlobalScope(TenantScope::class)
            ->where('public_token', $publicToken)
            ->where('is_active', true)
            ->firstOrFail();

        $this->validateOrigin($config);

        $isOnline = BusinessHoursHelper::isOnline(
            $config->business_hours ?? [],
            $config->timezone ?? 'America/Hermosillo'
        );

        return response()->json([
            'window_title'     => $config->window_title,
            'window_subtitle'  => $config->window_subtitle,
            'button_label'     => $config->button_label,
            'form_subtitle'    => $config->form_subtitle,
            'primary_color'    => $config->primary_color,
            'icon'             => $config->icon,
            'position'           => $config->position,
            'label_position'     => $config->label_position   ?? 'top',
            'label_visibility'   => $config->label_visibility ?? 'always',
            'welcome_message'    => $config->welcome_message,
            'offline_message'  => $config->offline_message,
            'interest_enabled' => $config->interest_enabled,
            'interest_type'    => $config->interest_type,
            'interest_options' => $config->interest_options ?? [],
            'privacy_enabled'  => $config->privacy_enabled,
            'privacy_type'     => $config->privacy_type,
            'privacy_text'     => $config->privacy_text,
            'privacy_url'      => $config->privacy_url,
            'size'             => $config->size ?? 'medium',
            'is_online'        => $isOnline,
        ])->withHeaders(array_merge($this->corsHeaders(), [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]));
    }

    public function start(Request $request, string $publicToken)
    {
        $config = WebchatConfig::withoutGlobalScope(TenantScope::class)
            ->where('public_token', $publicToken)
            ->where('is_active', true)
            ->firstOrFail();

        $this->validateOrigin($config);

        // Honeypot anti-bot
        if ($request->filled('website')) {
            return response()->json(['ok' => true])->withHeaders($this->corsHeaders());
        }

        $request->validate([
            'name'     => 'required|string|max:100',
            'phone'    => 'required|string|max:30',
            'email'    => 'required|email|max:150',
            'interest' => 'nullable|string|max:100',
            'page_url' => 'nullable|string|max:500',
        ]);

        $isOnline = BusinessHoursHelper::isOnline(
            $config->business_hours ?? [],
            $config->timezone ?? 'America/Hermosillo'
        );

        $campaignId = $config->omniQueue?->campaign_id;

        // Buscar lead existente por teléfono o email en el mismo tenant
        $lead = Lead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $config->tenant_id)
            ->where(function ($q) use ($request) {
                $q->where('phone', $request->phone)
                  ->orWhere('email', $request->email);
            })
            ->first();

        $timestamp = now()->setTimezone($config->timezone ?? 'America/Hermosillo')->format('m/d/Y H:i:s');
        $prefix    = $isOnline ? 'Webchat' : 'Webchat (offline — needs follow-up)';
        $interestNote = '[' . $timestamp . '] ' . $prefix
            . ($request->interest ? ': ' . $request->interest : '');

        if (!$lead) {
            [$firstName, $lastName] = $this->splitName($request->name);
            $lead = Lead::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id'   => $config->tenant_id,
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'phone'       => $request->phone,
                'email'       => $request->email,
                'source'      => 'Webchat',
                'status'      => 'new',
                'notes'       => $interestNote,
                'campaign_id' => $campaignId,
            ]);
        } else {
            $updates = [];
            if ($interestNote) {
                $updates['notes'] = $lead->notes ? $lead->notes . "\n" . $interestNote : $interestNote;
            }
            // Asignar campaña si el lead no la tiene aún
            if ($campaignId && !$lead->campaign_id) {
                $updates['campaign_id'] = $campaignId;
            }
            if ($updates) {
                $lead->update($updates);
            }
        }

        $session = WebchatSession::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id'         => $config->tenant_id,
            'webchat_config_id' => $config->id,
            'session_token'     => (string) Str::uuid(),
            'lead_id'           => $lead->id,
            'name'              => $request->name,
            'phone'             => $request->phone,
            'email'             => $request->email,
            'interest'          => $request->interest,
            'page_url'          => $request->page_url,
            'ip'                => $request->ip(),
            'user_agent'        => $request->userAgent(),
        ]);

        if ($isOnline) {
            $startsAiControlled = $this->automationForwardingService
                ->isEnabledForInbound((int) $config->tenant_id, 'chat');

            $conversation = Conversation::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id'     => $config->tenant_id,
                'channel'       => 'chat',
                'omni_queue_id' => $config->omni_queue_id,
                'lead_id'       => $lead->id,
                'from_name'     => $request->name,
                'from_email'    => $request->phone,
                'subject'       => $request->interest ?: $request->name,
                'status'        => 'pending',
                'direction'     => 'inbound',
                'wait_since'    => now(),
                'ai_bot_active' => $startsAiControlled,
            ]);

            $session->update([
                'conversation_id' => $conversation->id,
                'outcome'         => 'converted_to_conversation',
            ]);

            if ($config->welcome_message) {
                Message::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id'       => $config->tenant_id,
                    'conversation_id' => $conversation->id,
                    'direction'       => 'outbound',
                    'from_name'       => $config->window_title,
                    'from_email'      => 'bot@internal',
                    'to_email'        => $request->phone,
                    'body_text'       => $config->welcome_message,
                    'sent_at'         => now(),
                ]);
            }

            return response()->json([
                'mode'            => 'online',
                'session_token'   => $session->session_token,
                'conversation_id' => $conversation->id,
            ])->withHeaders($this->corsHeaders());
        }

        $this->assignOfflineLead($lead, $config->omniQueue);

        $session->update(['outcome' => 'offline_lead']);

        return response()->json([
            'mode'          => 'offline',
            'session_token' => $session->session_token,
        ])->withHeaders($this->corsHeaders());
    }

    public function message(Request $request, string $publicToken, string $sessionToken)
    {
        $session = WebchatSession::withoutGlobalScope(TenantScope::class)
            ->where('session_token', $sessionToken)
            ->firstOrFail();

        $request->validate(['body_text' => 'required|string|max:4096']);

        $bodyText = strip_tags($request->body_text);

        $msg = Message::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id'       => $session->tenant_id,
            'conversation_id' => $session->conversation_id,
            'direction'       => 'inbound',
            'from_name'       => $session->name,
            'from_email'      => $session->phone ?? $session->email,
            'to_email'        => 'chat@internal',
            'body_text'       => $bodyText,
            'sent_at'         => now(),
        ]);

        $conv = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($session->conversation_id);

        if ($conv) {
            Conversation::withoutTimestamps(fn() => $conv->update(['wait_since' => now()]));
            if ($conv->assigned_to) {
                event(new \App\Events\NewMessageReceived($msg, $conv));
            }

            $aiBotActive = Conversation::withoutGlobalScope(TenantScope::class)
                ->whereKey($conv->id)
                ->value('ai_bot_active');

            if ((bool) $aiBotActive) {
                $this->automationForwardingService->forwardInboundMessage($session->tenant_id, 'chat', [
                    'event'                => 'chat.inbound_message',
                    'tenant_id'            => $session->tenant_id,
                    'tenant_slug'          => $conv->tenant?->slug,
                    'channel'              => 'chat',
                    'conversation_id'      => $conv->id,
                    'message_id'           => $msg->id,
                    'external_message_id'  => null,
                    'from'                 => $session->phone ?? $session->email,
                    'to'                   => 'chat@internal',
                    'contact_name'         => $session->name,
                    'message_type'         => 'text',
                    'text'                 => $bodyText,
                    'received_at'          => $msg->sent_at->toIso8601String(),
                    'source'               => config('app.url'),
                    'raw'                  => [
                        'session_token' => $session->session_token,
                        'page_url'      => $session->page_url,
                        'interest'      => $session->interest,
                        'email'         => $session->email,
                        'phone'         => $session->phone,
                    ],
                ]);
            }
        }

        return response()->json([
            'id'      => $msg->id,
            'sent_at' => $msg->sent_at,
        ])->withHeaders($this->corsHeaders());
    }

    public function messages(Request $request, string $publicToken, string $sessionToken)
    {
        $session = WebchatSession::withoutGlobalScope(TenantScope::class)
            ->where('session_token', $sessionToken)
            ->firstOrFail();

        $msgs = Message::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $session->conversation_id)
            ->when($request->after_id, fn($q) => $q->where('id', '>', (int) $request->after_id))
            ->orderBy('sent_at')
            ->get(['id', 'from_name', 'body_text', 'sent_at', 'direction']);

        $conv = $session->conversation_id
            ? Conversation::withoutGlobalScope(TenantScope::class)
                ->find($session->conversation_id)
            : null;

        $agentName = $conv?->assigned_to
            ? \App\Models\User::find($conv->assigned_to)?->name
            : null;

        return response()->json([
            'messages'            => $msgs,
            'conversation_status' => $conv?->status,
            'agent_name'          => $agentName,
        ])->withHeaders($this->corsHeaders());
    }

    public function close(string $publicToken, string $sessionToken)
    {
        $session = WebchatSession::withoutGlobalScope(TenantScope::class)
            ->where('session_token', $sessionToken)
            ->first();

        if ($session && !$session->outcome) {
            $session->update(['outcome' => 'abandoned']);
        }

        return response()->json(['ok' => true])->withHeaders($this->corsHeaders());
    }

    public function widget()
    {
        $path = public_path('webchat/widget.js');
        if (!file_exists($path)) abort(404);

        return response()->file($path, [
            'Content-Type'                => 'application/javascript',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'public, max-age=300',
            'X-Content-Type-Options'      => 'nosniff',
        ]);
    }


    public function testPage(string $publicToken)
    {
        $config = WebchatConfig::withoutGlobalScope(TenantScope::class)
            ->where('public_token', $publicToken)
            ->where('is_active', true)
            ->firstOrFail();

        return view('webchat.test', compact('config', 'publicToken'));
    }

    private function assignOfflineLead(\App\Models\Lead $lead, ?\App\Models\OmniQueue $queue): void
    {
        if (!$queue || $lead->assigned_to) return;

        $agentId = match($queue->assignment_mode) {
            'direct'     => $queue->default_agent_id,
            'round_robin' => $queue->agents()
                ->wherePivot('active', true)
                ->get()
                ->sortBy(fn($agent) =>
                    \App\Models\Lead::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                        ->where('assigned_to', $agent->id)
                        ->where('campaign_id', $queue->campaign_id)
                        ->latest('created_at')
                        ->value('created_at') ?? '1970-01-01'
                )->first()?->id,
            'least_busy' => $queue->agents()
                ->wherePivot('active', true)
                ->get()
                ->sortBy(fn($agent) =>
                    \App\Models\Lead::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                        ->where('assigned_to', $agent->id)
                        ->where('campaign_id', $queue->campaign_id)
                        ->whereIn('status', ['new', 'active'])
                        ->count()
                )->first()?->id,
            default => null,
        };

        if ($agentId) {
            $lead->update(['assigned_to' => $agentId]);
        }
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
