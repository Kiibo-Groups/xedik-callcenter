<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AgentSession;
use App\Models\PauseReason;
use App\Services\AgentStatusService;
use App\Services\Voice\QueueMemberAvailabilityException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentStatusController extends Controller
{
    public function __construct(private AgentStatusService $service) {}

    /** GET /api/mobile/agent/status — estado actual + motivos de pausa del tenant */
    public function show(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        $session = AgentSession::where('user_id', $user->id)
            ->with('pauseReason')
            ->first();

        $pauseReasons = PauseReason::where('tenant_id', $user->tenant_id)
            ->active()
            ->get(['id', 'name', 'color', 'max_minutes']);

        return response()->json([
            'success'       => true,
            'status'        => $session?->status ?? 'offline',
            'status_since'  => $session?->status_since,
            'pause_reason'  => $session?->pauseReason ? [
                'id'          => $session->pauseReason->id,
                'name'        => $session->pauseReason->name,
                'max_minutes' => $session->pauseReason->max_minutes,
            ] : null,
            'pause_reasons' => $pauseReasons,
        ]);
    }

    /** POST /api/mobile/agent/status — cambiar estado */
    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        $request->validate([
            'status'          => 'required|in:ready,paused,offline',
            'pause_reason_id' => 'nullable|integer|exists:pause_reasons,id',
        ]);

        if ($request->status === 'paused' && ! $request->pause_reason_id) {
            return response()->json(['success' => false, 'error' => 'Pause reason required.'], 422);
        }

        if ($this->service->isManualStatusChangeLocked($user)) {
            return response()->json([
                'success' => false,
                'code' => 'voice_status_locked',
                'error' => 'Status changes are unavailable while handling a voice interaction. Choose the next status when submitting the disposition.',
            ], 409);
        }

        try {
            $session = $this->service->changeStatus(
                $user,
                $request->status,
                $request->pause_reason_id,
            );
        } catch (QueueMemberAvailabilityException $e) {
            return response()->json([
                'success' => false,
                'code' => 'voice_not_ready',
                'error' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'success'      => true,
            'status'       => $session->status,
            'status_since' => $session->status_since,
        ]);
    }

    /** POST /api/mobile/agent/ping — heartbeat cada 60s */
    public function ping(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');
        $this->service->ping($user);

        return response()->json(['success' => true]);
    }
}
