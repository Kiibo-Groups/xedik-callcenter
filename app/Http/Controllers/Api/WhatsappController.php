<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Scopes\TenantScope;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappController extends Controller
{
    // ── POST /api/whatsapp/bot-reply ────────────────────────────────────────
    // Lets an external automation (n8n/SAARBot) send an outbound WhatsApp
    // reply into an existing conversation. The conversation is left exactly
    // as it was (status/assigned_to untouched) — the existing assignment
    // engine (conversations:assign) picks it up normally.

    public function sendBotReply(Request $request, WhatsAppOutboundService $outbound): JsonResponse
    {
        /** @var \App\Models\AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $tenantId    = $accessToken->tenant_id;

        Log::info('[WhatsAppBotReply] incoming request', [
            'tenant_id'    => $tenantId,
            'content_type' => $request->header('Content-Type'),
            'raw_body'     => $request->getContent(),
            'parsed_input' => $request->all(),
        ]);

        $validated = $request->validate([
            'conversation_id' => 'required|integer',
            'to'              => 'required|string',
            'message'         => 'required|string|max:4096',
            'type_message'    => 'nullable|string',
        ]);

        $type = $validated['type_message'] ?? 'text';
        if ($type !== 'text') {
            return response()->json([
                'success' => false,
                'error'   => "Unsupported type_message '{$type}'. Only 'text' is supported.",
            ], 422);
        }

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['conversation_id'])
            ->first();

        if (!$conversation) {
            return response()->json(['success' => false, 'error' => 'Conversation not found.'], 404);
        }

        if ($conversation->channel !== 'whatsapp') {
            return response()->json(['success' => false, 'error' => 'Conversation is not a WhatsApp conversation.'], 422);
        }

        $account = $conversation->whatsappAccount;
        if (!$account || !$account->is_active) {
            return response()->json(['success' => false, 'error' => 'No active WhatsApp account linked to this conversation.'], 422);
        }

        // 'to' is informational/audit only — the actual send always targets the
        // conversation's own external_id, so a stale/mismatched 'to' from the
        // caller can never misroute a message to the wrong contact. Compared
        // as last-10-digits to tolerate country-code/prefix formatting drift.
        $last10 = fn (string $phone): string => substr(preg_replace('/\D/', '', $phone), -10);
        if ($last10($validated['to']) !== $last10((string) $conversation->external_id)) {
            Log::warning('[WhatsAppBotReply] "to" does not match conversation external_id — sending to conversation external_id anyway', [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conversation->id,
                'to_received'     => $validated['to'],
                'conversation_external_id' => $conversation->external_id,
            ]);
        }

        // Delegates to the same outbound sender used by the generic
        // /api/automation/conversations/{conversation}/reply endpoint — no
        // duplicated Graph API call.
        $result = $outbound->sendText($conversation, $account, $validated['message'], 'SAARBot', null);

        if (!$result['ok']) {
            Log::warning('[WhatsAppBotReply] send failed', [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conversation->id,
                'error'           => $result['error'] ?? null,
            ]);
            return response()->json(['success' => false, 'error' => $result['error'] ?? 'Send failed.'], $result['status'] ?? 502);
        }

        $message = $result['message'];

        Log::info('[WhatsAppBotReply] sent', [
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'wamid'           => $result['wamid'] ?? null,
        ]);

        return response()->json([
            'success'         => true,
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'wamid'           => $result['wamid'] ?? null,
        ]);
    }

    // ── POST /api/whatsapp/handoff ──────────────────────────────────────────
    // Legacy/compat alias of POST /api/automation/conversations/{id}/handoff,
    // kept during the transition to the generic automation routes. Body takes
    // conversation_id instead of a path parameter, matching bot-reply's shape.

    public function handoff(Request $request): JsonResponse
    {
        /** @var \App\Models\AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $tenantId    = $accessToken->tenant_id;

        $validated = $request->validate([
            'conversation_id' => 'required|integer',
            'reason'          => 'nullable|string|max:500',
        ]);

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['conversation_id'])
            ->first();

        if (!$conversation) {
            return response()->json(['success' => false, 'error' => 'Conversation not found.'], 404);
        }

        if (!$conversation->ai_bot_active) {
            return response()->json([
                'success' => true, 'ok' => true,
                'already_handed_off' => true,
                'conversation_id'    => $conversation->id,
            ]);
        }

        $conversation->update(['ai_bot_active' => false]);

        Log::info('[WhatsAppHandoff]', [
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'reason'          => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true, 'ok' => true,
            'conversation_id' => $conversation->id,
            'status'          => $conversation->status,
            'ai_bot_active'   => false,
            'handoff'         => 'human_queue',
        ]);
    }
}
