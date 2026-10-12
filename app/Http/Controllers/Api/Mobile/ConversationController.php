<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Services\Storage\CrmFileService;
use App\Services\ZernioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    private ?CrmFileService $fileService = null;

    private function fileService(): CrmFileService
    {
        return $this->fileService ??= app(CrmFileService::class);
    }

    // ── GET /api/mobile/conversations ────────────────────────────────────────
    // Query: status=active|pending|resolved  channel=email|whatsapp|chat
    //        mine=1 (assigned to me)  page=N  per_page=N

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $user     = $request->attributes->get('mobile_user');

        $query = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with(['lead:id,first_name,last_name,email,phone'])
            ->orderBy('wait_since', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            // Por defecto solo activas y pendientes — resolved no aparece en la bandeja
            $query->whereIn('status', ['active', 'pending']);
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->query('channel'));
        }

        if ($request->boolean('mine')) {
            $query->where('assigned_to', $user->id);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginated->map(fn ($c) => $this->formatConversation($c)),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    // ── GET /api/mobile/conversations/{id} ───────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with(['lead:id,first_name,last_name,email,phone,status'])
            ->findOrFail($id);

        $messages = Message::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $conversation->id)
            ->with(['crmFiles' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('sent_at', 'asc')
            ->get()
            ->map(fn ($m) => $this->formatMessage($m));

        return response()->json([
            'success'      => true,
            'conversation' => $this->formatConversation($conversation),
            'messages'     => $messages,
        ]);
    }

    // ── POST /api/mobile/conversations/{id}/reply ─────────────────────────────

    public function reply(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $user     = $request->attributes->get('mobile_user');

        $request->validate([
            'body' => 'required|string|max:4096',
        ]);

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with(['whatsappAccount', 'socialAccount'])
            ->findOrFail($id);

        $body      = $request->string('body');
        $messageId = null;
        $fromEmail = $user->email;

        if ($conversation->channel === 'whatsapp') {
            $account = $conversation->whatsappAccount;
            if (! $account) {
                return response()->json(['success' => false, 'error' => 'No WhatsApp account linked.'], 400);
            }

            $apiBase    = env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v25.0');
            $waResponse = \Illuminate\Support\Facades\Http::withToken($account->access_token)
                ->post("{$apiBase}/{$account->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $conversation->external_id,
                    'type'              => 'text',
                    'text'              => ['body' => (string) $body],
                ]);

            if ($waResponse->failed()) {
                return response()->json(['success' => false, 'error' => 'WhatsApp API error: ' . $waResponse->body()], 500);
            }

            $messageId = $waResponse->json('messages.0.id');

        } elseif (in_array($conversation->channel, ['facebook', 'instagram'])) {
            $account = $conversation->socialAccount;
            if (! $account) {
                return response()->json(['success' => false, 'error' => 'No social account linked.'], 400);
            }

            try {
                $result    = app(ZernioService::class)->sendMessage(
                    $conversation->external_id,
                    $account->zernio_account_id,
                    (string) $body,
                );
                $messageId = $result['message_id'] ?? null;
                $fromEmail = $account->username ?? $account->display_name ?? $user->email;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Mobile sendSocial error: ' . $e->getMessage());
                return response()->json(['success' => false, 'error' => 'Could not send message. The messaging window may have expired.'], 500);
            }
        }

        $message = Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $fromEmail,
            'from_name'       => $user->name,
            'to_email'        => $conversation->external_id ?? $conversation->from_email,
            'body_text'       => (string) $body,
            'body_html'       => null,
            'message_id'      => $messageId,
            'sent_by'         => $user->id,
            'sent_at'         => now(),
        ]);

        $conversation->update(['wait_since' => now()]);

        return response()->json([
            'success' => true,
            'message' => $this->formatMessage($message),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function formatConversation(Conversation $c): array
    {
        return [
            'id'           => $c->id,
            'channel'      => $c->channel,
            'status'       => $c->status,
            'subject'      => $c->subject,
            'from_name'    => $c->from_name,
            'from_email'   => $c->from_email,
            'direction'    => $c->direction,
            'wait_since'   => $c->wait_since,
            'assigned_to'  => $c->assigned_to,
            'lead_id'      => $c->lead_id,
            'lead'         => $c->lead ? [
                'id'         => $c->lead->id,
                'first_name' => $c->lead->first_name,
                'last_name'  => $c->lead->last_name,
                'email'      => $c->lead->email,
                'phone'      => $c->lead->phone,
            ] : null,
        ];
    }

    private function formatMessage(Message $m): array
    {
        $mediaUrl      = null;
        $mediaFilename = null;
        $referral      = null;

        if ($m->media_type) {
            $crmFile = $m->relationLoaded('crmFiles')
                ? $m->crmFiles->first()
                : \App\Models\CrmFile::where('message_id', $m->id)->where('status', 'active')->first();

            if ($crmFile) {
                try {
                    $mediaUrl      = $this->fileService()->generateTemporaryUrl($crmFile->id, null, 60);
                    $mediaFilename = $crmFile->original_filename;
                } catch (\Throwable) {
                    // URL generation failed — media won't display but message still shows
                }
            }
        }

        if ($m->referral_data) {
            $referral = [
                'source_url' => $m->referral_data['source_url'] ?? null,
                'headline'   => $m->referral_data['headline'] ?? null,
                'body'       => $m->referral_data['body'] ?? null,
                'image_url'  => null,
            ];

            if (!empty($m->referral_data['crm_file_id'])) {
                try {
                    $referral['image_url'] = $this->fileService()->generateTemporaryUrl(
                        $m->referral_data['crm_file_id'], null, 60
                    );
                } catch (\Throwable) {}
            }
        }

        return [
            'id'             => $m->id,
            'direction'      => $m->direction,
            'from_name'      => $m->from_name,
            'from_email'     => $m->from_email,
            'body_text'      => $m->body_text,
            'body_html'      => $m->body_html,
            'media_type'     => $m->media_type,
            'media_url'      => $mediaUrl,
            'media_filename' => $mediaFilename,
            'referral'       => $referral,
            'sent_at'        => $m->sent_at,
            'attachments'    => [],
        ];
    }
}
