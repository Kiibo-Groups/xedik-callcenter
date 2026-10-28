<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Scopes\TenantScope;
use App\Services\Automation\AutomationReplyRouterService;
use App\Services\Automation\ConversationResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Generic conversational-automation endpoints (n8n/AI callers), channel-agnostic.
 * Auth is the same tenant-scoped AccessToken mechanism as the rest of
 * routes/api.php's Group 1 (token.auth middleware).
 */
class ConversationAutomationController extends Controller
{
    private const LOG_REPLY   = '[ConversationAutomationReply]';
    private const LOG_HANDOFF = '[ConversationAutomationHandoff]';
    private const LOG_RESOLVE = '[ConversationAutomationResolve]';

    // ── POST /api/automation/conversations/{conversation}/handoff ──────────

    public function handoff(Request $request, int $conversation): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $request->validate(['reason' => 'nullable|string|max:500']);

        $conv = $this->findConversation($conversation, $tenantId);
        if (!$conv) {
            return response()->json(['ok' => false, 'error' => 'Conversation not found.'], 404);
        }

        if (!$conv->ai_bot_active) {
            return response()->json([
                'ok' => true,
                'already_handed_off' => true,
                'conversation_id'    => $conv->id,
            ]);
        }

        // Only the AI-control flag (and notes, below) change — status,
        // omni_queue_id and assignment are left untouched; the existing
        // conversations:assign cron picks the conversation up on its next run.
        $updates = ['ai_bot_active' => false];

        $reason = trim((string) $request->input('reason'));
        if ($reason !== '') {
            $reasonLine    = '[Handoff] ' . $reason;
            $existingNotes = trim((string) $conv->notes);
            // Append, never overwrite — and never duplicate the exact same
            // reason line if this handoff is somehow re-applied.
            if (!str_contains($existingNotes, $reasonLine)) {
                $updates['notes'] = $existingNotes !== ''
                    ? $existingNotes . "\n" . $reasonLine
                    : $reasonLine;
            }
        }

        $conv->update($updates);

        try {
            \Illuminate\Support\Facades\Artisan::call('conversations:assign');
        } catch (\Throwable $e) {
            Log::warning(self::LOG_HANDOFF . ' instant assign failed: ' . $e->getMessage());
        }

        Log::info(self::LOG_HANDOFF, [
            'tenant_id'       => $tenantId,
            'conversation_id' => $conv->id,
            'reason'          => $request->input('reason'),
        ]);

        return response()->json([
            'ok'              => true,
            'conversation_id' => $conv->id,
            'status'          => $conv->status,
            'ai_bot_active'   => false,
            'handoff'         => 'human_queue',
        ]);
    }

    // ── POST /api/automation/conversations/{conversation}/reply ────────────

    public function reply(Request $request, int $conversation, AutomationReplyRouterService $router): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'message'      => 'required|string|max:4096',
            'type_message' => 'nullable|string',
            // Legacy /api/whatsapp/bot-reply callers may still send these — accepted
            // but never trusted over the conversation's own stored data.
            'to'           => 'nullable|string',
        ]);

        $type = $validated['type_message'] ?? 'text';
        if ($type !== 'text') {
            return response()->json([
                'ok' => false, 'success' => false,
                'error' => "Unsupported type_message '{$type}'. Only 'text' is supported.",
            ], 422);
        }

        $conv = $this->findConversation($conversation, $tenantId);
        if (!$conv) {
            return response()->json(['ok' => false, 'success' => false, 'error' => 'Conversation not found.'], 404);
        }

        // El opt-out de SMS se aplica dentro de SmsService::sendReply() (vía
        // el router más abajo) — único punto de verdad. Duplicar el check
        // aquí impedía la excepción legítima de "responder al inbound que el
        // cliente acaba de mandar tras el STOP": este controller nunca sabe
        // si hay una respuesta pendiente del cliente, así que bloqueaba
        // siempre, sin importar el contexto de la conversación.

        if (in_array($conv->status, ['resolved', 'archived'], true)) {
            return response()->json([
                'ok' => false, 'success' => false,
                'error' => "Cannot reply — conversation is {$conv->status}.",
            ], 422);
        }

        $result = $router->send($conv, $validated['message']);

        if (!$result['ok']) {
            Log::warning(self::LOG_REPLY . ' send failed', [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conv->id,
                'channel'         => $conv->channel,
                'error'           => $result['error'] ?? null,
            ]);
            return response()->json(['ok' => false, 'success' => false, 'error' => $result['error'] ?? 'Send failed.'], $result['status'] ?? 502);
        }

        Log::info(self::LOG_REPLY . ' sent', [
            'tenant_id'         => $tenantId,
            'conversation_id'   => $conv->id,
            'channel'           => $conv->channel,
            'message_id'        => $result['message']->id,
            'external_id'       => $result['external_id'] ?? null,
        ]);

        return response()->json([
            'ok'              => true,
            'success'         => true,
            'conversation_id' => $conv->id,
            'channel'         => $conv->channel,
            'message_id'      => $result['message']->id,
            'external_id'     => $result['external_id'] ?? null,
        ]);
    }

    // ── POST /api/automation/conversations/{conversation}/resolve ──────────

    public function resolve(Request $request, int $conversation, ConversationResolutionService $resolver): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $validated = $request->validate([
            'disposition'        => 'nullable|string|max:255',
            'resolution_summary' => 'nullable|string|max:500',
        ]);

        $conv = $this->findConversation($conversation, $tenantId);
        if (!$conv) {
            return response()->json(['ok' => false, 'error' => 'Conversation not found.'], 404);
        }

        $result = $resolver->resolveByAi(
            $conv,
            $validated['disposition'] ?? null,
            $validated['resolution_summary'] ?? null,
        );

        if (!$result['ok']) {
            Log::info(self::LOG_RESOLVE . ' conflict', [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conv->id,
                'error'           => $result['error'] ?? null,
            ]);
            return response()->json([
                'ok'              => false,
                'conversation_id' => $conv->id,
                'error'           => $result['error'] ?? 'Could not resolve conversation.',
            ], $result['status'] ?? 409);
        }

        $resolved = $result['conversation'];

        return response()->json([
            'ok'                => true,
            'conversation_id'   => $resolved->id,
            'status'            => $resolved->status,
            'resolved_source'   => $resolved->resolved_source,
            'already_resolved'  => $result['already_resolved'] ?? false,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function tenantId(Request $request): int
    {
        /** @var \App\Models\AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        return (int) $accessToken->tenant_id;
    }

    private function findConversation(int $id, int $tenantId): ?Conversation
    {
        return Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();
    }
}
