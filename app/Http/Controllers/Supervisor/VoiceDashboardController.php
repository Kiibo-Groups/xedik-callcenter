<?php

declare(strict_types=1);

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Services\Telephony\VoiceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VoiceDashboardController extends Controller
{
    public function __construct(
        private readonly VoiceDashboardService $dashboardService
    ) {
    }

    public function index(Request $request): View
    {
        return view('supervisor.dashboard', [
            'dashboardDataUrl' => route('supervisor.dashboard.data'),
            'filterOptions' => $this->dashboardService->filterOptionsForUser($request->user()),
            'tenantKey' => $request->user()->tenant_id === null ? 'global' : (string) $request->user()->tenant_id,
            'userKey' => (string) $request->user()->id,
            'timezone' => (string) config('app.timezone', 'UTC'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => ['nullable', 'integer', 'min:1'],
            'queue_id' => ['nullable', 'integer', 'min:1'],
            'preset' => ['nullable', 'string', 'in:today,yesterday,last_7_days,last_30_days,this_month,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json($this->dashboardService->snapshotForUser($request->user(), $validated));
    }
}
