<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Conversation;
use App\Models\ConversationReport;
use App\Models\Scopes\TenantScope;
use App\Models\WebchatSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves a conversation on behalf of an AI/automation actor, mirroring the
 * side effects of ConsoleController::disposition() (conversations.update +
 * conversation_reports snapshot) without requiring an authenticated agent
 * session. Unlike the human flow, conversation_reports is upserted via
 * updateOrCreate — conversation_id is UNIQUE, and a human may already have
 * created a report row for this conversation in a prior cycle.
 */
class ConversationResolutionService
{
    private const LOG_PREFIX = '[ConversationAutomationResolve]';

    /**
     * @return array{ok: bool, status?: int, already_resolved?: bool, conflict?: bool, conversation?: Conversation, error?: string}
     */
    public function resolveByAi(Conversation $conversation, ?string $disposition, ?string $summary): array
    {
        // Idempotent: already resolved by AI — return success without redoing side effects.
        if ($conversation->status === 'resolved' && $conversation->resolved_source === 'ai') {
            return ['ok' => true, 'already_resolved' => true, 'conversation' => $conversation];
        }

        // Never silently overwrite a resolution that wasn't AI's.
        if ($conversation->status === 'resolved' && $conversation->resolved_source !== 'ai') {
            Log::info(self::LOG_PREFIX . ' conflict — already resolved by ' . ($conversation->resolved_source ?? 'unknown'), [
                'conversation_id' => $conversation->id,
            ]);
            return [
                'ok'      => false,
                'status'  => 409,
                'conflict' => true,
                'error'   => 'Conversation was already resolved by ' . ($conversation->resolved_source ?? 'a human agent') . '.',
                'conversation' => $conversation,
            ];
        }

        // A human is actively working this conversation (not AI-controlled) — don't
        // silently resolve out from under them.
        if ($conversation->status === 'active' && !$conversation->ai_bot_active) {
            Log::info(self::LOG_PREFIX . ' conflict — actively owned by human agent', [
                'conversation_id' => $conversation->id,
                'assigned_to'     => $conversation->assigned_to,
            ]);
            return [
                'ok'      => false,
                'status'  => 409,
                'conflict' => true,
                'error'   => 'Conversation is actively assigned to a human agent.',
                'conversation' => $conversation,
            ];
        }

        $resolvedAt        = now();
        $dispositionLabel  = $disposition ?: 'Resolved by AI';

        $firstMsg = $conversation->channel === 'chat'
            ? null
            : $conversation->messages()->reorder()->orderBy('sent_at', 'asc')
                ->where('from_email', '!=', 'bot@internal')
                ->first();
        $direction = $firstMsg?->direction ?? ($conversation->direction ?? 'inbound');

        // Preserve existing notes (e.g. an AI handoff reason) when no
        // resolution_summary is given — same final value is used below for
        // both conversations.notes and the report.
        $finalNotes = $summary ?? $conversation->notes;

        DB::transaction(function () use ($conversation, $resolvedAt, $dispositionLabel, $direction, $finalNotes, $firstMsg) {
            $conversation->update([
                'status'          => 'resolved',
                'disposition'     => $dispositionLabel,
                'resolved_at'     => $resolvedAt,
                'resolved_source' => 'ai',
                'ai_bot_active'   => false,
                'direction'       => $direction,
                'notes'           => $finalNotes,
            ]);

            $isOutbound   = $direction === 'outbound';
            $contactEmail = $isOutbound ? $firstMsg?->to_email : $conversation->from_email;

            $conversation->load('lead');
            $leadName = $conversation->lead
                ? trim($conversation->lead->first_name . ' ' . $conversation->lead->last_name)
                : null;

            $durationSeconds = $conversation->wait_since
                ? (int) $conversation->wait_since->diffInSeconds($resolvedAt)
                : null;

            $preview = $firstMsg
                ? Str::limit(strip_tags($firstMsg->body_text ?? $firstMsg->body_html ?? ''), 100)
                : null;

            try {
                ConversationReport::updateOrCreate(
                    ['conversation_id' => $conversation->id],
                    [
                        'tenant_id'              => $conversation->tenant_id,
                        'agent_id'               => null,
                        'agent_name'             => 'AI Bot',
                        'lead_id'                => $conversation->lead_id,
                        'lead_name'              => $leadName,
                        'contact_email'          => $contactEmail,
                        'channel'                => $conversation->channel,
                        'direction'              => $isOutbound ? 'outbound' : 'inbound',
                        'subject'                => $conversation->subject,
                        'first_message_preview'  => $preview,
                        'disposition'            => $dispositionLabel,
                        'resolved_source'        => 'ai',
                        'message_count'          => $conversation->messages()->count(),
                        'inbound_message_count'  => $conversation->messages()->where('direction', 'inbound')->count(),
                        'outbound_message_count' => $conversation->messages()->where('direction', 'outbound')->count(),
                        'duration_seconds'       => $durationSeconds,
                        'wait_since'             => $conversation->wait_since,
                        'resolved_at'            => $resolvedAt,
                        'notes'                  => $finalNotes,
                    ]
                );
            } catch (\Throwable $e) {
                Log::error(self::LOG_PREFIX . ' ConversationReport upsert error: ' . $e->getMessage(), [
                    'conversation_id' => $conversation->id,
                ]);
            }
        });

        if ($conversation->channel === 'chat') {
            $session = WebchatSession::withoutGlobalScope(TenantScope::class)
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
                    Log::warning(self::LOG_PREFIX . ' chat Pusher error: ' . $e->getMessage());
                }
            }
        }

        Log::info(self::LOG_PREFIX . ' resolved', [
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
        ]);

        return ['ok' => true, 'already_resolved' => false, 'conversation' => $conversation->fresh()];
    }
}
