<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Telephony\RealtimeVoiceMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RealtimeVoiceMonitorController extends Controller
{
    public function __construct(
        private readonly RealtimeVoiceMonitorService $monitorService
    ) {
    }

    public function index(Request $request): View
    {
        return view('supervisor.realtime-voice', [
            'monitorDataUrl' => route('supervisor.realtime-voice.data'),
            'filterOptions' => $this->monitorService->filterOptionsForUser($request->user()),
            'staleSeconds' => (int) config('realtime_monitor.stale_seconds', 15),
            'offlineSeconds' => (int) config('realtime_monitor.offline_seconds', 30),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->monitorService->snapshotForUser($request->user(), $request->only([
            'campaign_id',
            'queue_id',
        ])));
    }
}
