<?php

namespace App\Http\Controllers;

use App\Services\AgentStatusService;
use App\Services\Voice\AgentConsoleVoiceEligibilityService;
use App\Services\Voice\QueueMemberAvailabilityException;
use App\Models\PauseReason;
use App\Models\AgentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgentController extends Controller
{
    public function __construct(
        private AgentStatusService $statusService,
        private AgentConsoleVoiceEligibilityService $voiceEligibility
    ) {}

    // Ping heartbeat
    public function ping(): \Illuminate\Http\JsonResponse
    {
        $this->statusService->ping(Auth::user());
        return response()->json(['ok' => true]);
    }

    // Cambiar estado
    public function setStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'status'          => 'required|in:ready,paused,offline',
            'pause_reason_id' => 'nullable|exists:pause_reasons,id',
            'browser_tab_id'  => 'nullable|string|max:120',
        ]);

        if ($request->status === 'paused' && !$request->pause_reason_id) {
            return response()->json(['error' => 'Pause reason required.'], 422);
        }

        $user = Auth::user();

        if ($this->statusService->isManualStatusChangeLocked($user)) {
            return response()->json([
                'ok' => false,
                'code' => 'voice_status_locked',
                'message' => 'Status changes are unavailable while handling a voice interaction. Choose the next status when submitting the disposition.',
            ], 409);
        }

        if ($request->status === 'ready'
            && $this->voiceEligibility->requiresReadyVoiceGuard($user)
            && !$this->voiceEligibility->hasOperationalSessionForTab(
                $user,
                $request->input('browser_tab_id')
            )) {
            return response()->json([
                'ok' => false,
                'code' => 'voice_not_ready',
                'message' => 'Voice is still connecting. Please try again.',
            ], 409);
        }

        try {
            $session = $this->statusService->changeStatus(
                $user,
                $request->status,
                $request->pause_reason_id,
                $request->input('browser_tab_id')
            );
        } catch (QueueMemberAvailabilityException $e) {
            return response()->json([
                'ok' => false,
                'code' => 'voice_not_ready',
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok'      => true,
            'status'  => $session->status,
            'since'   => $session->status_since,
        ]);
    }

    // Estado actual del agente
    public function status(): \Illuminate\Http\JsonResponse
    {
        $session = AgentSession::with('pauseReason')
                               ->where('user_id', Auth::id())
                               ->first();

        $pauseReasons = PauseReason::active()->get();

        $effective = $this->statusService->resolveEffectiveStatus(Auth::user());

        return response()->json([
            'session'       => $session,
            'pause_reasons' => $pauseReasons,
            'effective_status' => $effective['status'],
            'effective_since'  => $effective['since'],
        ]);
    }
}
