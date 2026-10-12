<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CallsReportRequest;
use App\Models\Campaign;
use App\Models\DialerDisposition;
use App\Models\TelephonyCall;
use App\Models\User;
use App\Services\Reports\CallsReportService;
use App\Services\Telephony\RecordingUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CallsReportController extends Controller
{
    public function index(CallsReportRequest $request, CallsReportService $report)
    {
        $user = Auth::user();
        $filters = $request->filters();
        $viewType = $filters['view_type'];

        $calls = $viewType === 'detailed' ? $report->calls($user, $filters) : collect();
        $grouped = $viewType === 'agent_summary' ? $report->groupedByAgent($user, $filters) : collect();
        $metrics = $viewType === 'agent_summary'
            ? $report->groupMetrics($user, $filters)
            : $report->metrics($user, $filters);

        $agents = User::where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $campaigns = Campaign::where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $queues = $report->queueOptions($user);

        $callModes = TelephonyCall::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->whereNotNull('call_mode')
            ->where('call_mode', '<>', '')
            ->distinct()
            ->orderBy('call_mode')
            ->pluck('call_mode');

        $dispositions = DialerDisposition::orderBy('name')->get(['id', 'name']);

        return view('reports.calls', compact('calls', 'grouped', 'metrics', 'filters', 'viewType', 'agents', 'campaigns', 'queues', 'callModes', 'dispositions', 'report'));
    }

    public function export(CallsReportRequest $request, CallsReportService $report)
    {
        return $report->exportCsv(Auth::user(), $request->filters());
    }

    public function show(TelephonyCall $call, CallsReportService $report): JsonResponse
    {
        return response()->json($report->detail($call, Auth::user()));
    }

    public function recording(TelephonyCall $call, RecordingUrlService $recordings, Request $request): JsonResponse
    {
        $user = Auth::user();
        $this->assertCallTenant($call, $user, $request, 'play');

        try {
            $signed = $recordings->inlineUrl($call);
        } catch (RuntimeException $e) {
            $this->logRecordingAccess($request, $user, $call, 'play', 'error');
            Log::error('[calls-report][recording] signing failed', [
                'user_id' => $user?->id,
                'tenant_id' => $call->tenant_id,
                'telephony_call_id' => $call->id,
                'linkedid' => $call->linkedid,
                'action' => 'play',
            ]);

            return response()->json(['ok' => false, 'message' => 'Recording service unavailable.'], 500);
        }

        if ($signed === null) {
            $this->logRecordingAccess($request, $user, $call, 'play', 'unavailable');

            return response()->json(['ok' => false, 'message' => 'Recording unavailable.'], 422);
        }

        $this->logRecordingAccess($request, $user, $call, 'play', 'granted');

        return response()->json(['ok' => true] + $signed);
    }

    public function recordingDownload(TelephonyCall $call, RecordingUrlService $recordings, Request $request): RedirectResponse|JsonResponse
    {
        $user = Auth::user();
        $this->assertCallTenant($call, $user, $request, 'download');

        try {
            $signed = $recordings->attachmentUrl($call);
        } catch (RuntimeException $e) {
            $this->logRecordingAccess($request, $user, $call, 'download', 'error');
            Log::error('[calls-report][recording] signing failed', [
                'user_id' => $user?->id,
                'tenant_id' => $call->tenant_id,
                'telephony_call_id' => $call->id,
                'linkedid' => $call->linkedid,
                'action' => 'download',
            ]);

            return response()->json(['ok' => false, 'message' => 'Recording service unavailable.'], 500);
        }

        if ($signed === null) {
            $this->logRecordingAccess($request, $user, $call, 'download', 'unavailable');
            abort(404);
        }

        $this->logRecordingAccess($request, $user, $call, 'download', 'granted');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true] + $signed);
        }

        return redirect()->away($signed['url']);
    }

    private function assertCallTenant(TelephonyCall $call, ?User $user, Request $request, string $action): void
    {
        if (!$user || (int) $call->tenant_id !== (int) $user->tenant_id) {
            $this->logRecordingAccess($request, $user, $call, $action, 'denied');
            abort(404);
        }
    }

    private function logRecordingAccess(Request $request, ?User $user, TelephonyCall $call, string $action, string $result): void
    {
        Log::info('[calls-report][recording] access', [
            'user_id' => $user?->id,
            'tenant_id' => $call->tenant_id,
            'telephony_call_id' => $call->id,
            'linkedid' => $call->linkedid,
            'action' => $action,
            'timestamp' => now()->toIso8601String(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'result' => $result,
        ]);
    }
}
