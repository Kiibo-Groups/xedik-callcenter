<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationReport;
use App\Models\DialerDisposition;
use App\Models\Scopes\TenantScope;
use App\Services\PreviewDialerLeadDispositionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispositionController extends Controller
{
    /** GET /api/mobile/dispositions — disposiciones activas del tenant */
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        $dispositions = DialerDisposition::where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'bg_color', 'description', 'requires_callback_at']);

        return response()->json([
            'success' => true,
            'data'    => $dispositions,
        ]);
    }

    /** POST /api/mobile/conversations/{id}/disposition */
    public function store(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('id', $id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $conversation) {
            return response()->json(['success' => false, 'error' => 'Conversation not found.'], 404);
        }

        $request->validate([
            'disposition_id' => 'required|integer',
            'notes'          => 'nullable|string|max:500',
            'callback_at'    => 'nullable|date',
        ]);

        $disposition = DialerDisposition::withoutGlobalScope(TenantScope::class)
            ->where('id', $request->disposition_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $disposition) {
            return response()->json(['success' => false, 'error' => 'Disposition not found.'], 404);
        }

        $resolvedAt = now();
        $callbackAt = $request->filled('callback_at')
            ? Carbon::parse((string) $request->input('callback_at'))
            : null;
        $dispositionSync = app(PreviewDialerLeadDispositionSyncService::class);
        $requiresPreviewCallbackAt = $conversation->channel === 'voice'
            && $conversation->lead_list_id
            && $conversation->lead_list_row_id
            && $dispositionSync->requiresCallbackAt($disposition);

        if ($requiresPreviewCallbackAt && !$callbackAt) {
            return response()->json([
                'success' => false,
                'error' => 'Callback date/time is required for this disposition.',
            ], 422);
        }

        // Determinar dirección desde el primer mensaje
        $firstMsg  = null;
        if ($conversation->channel === 'chat') {
            $direction = 'inbound';
        } elseif ($conversation->channel === 'voice') {
            $direction = $conversation->direction ?? 'inbound';
        } else {
            $firstMsg  = $conversation->messages()
                ->reorder()
                ->orderBy('sent_at', 'asc')
                ->where('from_email', '!=', 'bot@internal')
                ->first();
            $direction = $firstMsg?->direction ?? 'inbound';
        }

        // Preserve existing notes (e.g. an AI handoff reason) when the agent
        // doesn't send anything new — same final value is used below for
        // both conversations.notes and the report.
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
            (int) $user->id,
            $callbackAt,
        );

        // Snapshot en conversation_reports
        try {
            $conversation->load('lead');
            $lead         = $conversation->lead;
            $leadName     = $lead ? trim("{$lead->first_name} {$lead->last_name}") : null;
            $contactEmail = $conversation->from_email;

            $firstMsg    = $firstMsg ?? $conversation->messages()->reorder()->orderBy('sent_at', 'asc')->first();
            $preview     = $firstMsg
                ? Str::limit(strip_tags($firstMsg->body_text ?? $firstMsg->body_html ?? ''), 100)
                : '';

            $durationSeconds = $conversation->wait_since
                ? (int) $resolvedAt->diffInSeconds($conversation->wait_since)
                : 0;

            ConversationReport::create([
                'tenant_id'              => $conversation->tenant_id,
                'conversation_id'        => $conversation->id,
                'agent_id'               => $user->id,
                'agent_name'             => $user->name,
                'lead_id'                => $conversation->lead_id,
                'lead_name'              => $leadName,
                'contact_email'          => $contactEmail,
                'channel'                => $conversation->channel,
                'direction'              => $direction,
                'subject'                => $conversation->subject,
                'first_message_preview'  => $preview,
                'disposition'            => $disposition->name,
                'resolved_source'        => 'human',
                'message_count'          => $conversation->messages()->count(),
                'inbound_message_count'  => $conversation->messages()->where('direction', 'inbound')->count(),
                'outbound_message_count' => $conversation->messages()->where('direction', 'outbound')->count(),
                'duration_seconds'       => $durationSeconds,
                'wait_since'             => $conversation->wait_since,
                'resolved_at'            => $resolvedAt,
                'notes'                  => $finalNotes,
            ]);
        } catch (\Throwable $e) {
            Log::error('Mobile disposition snapshot error: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }
}
