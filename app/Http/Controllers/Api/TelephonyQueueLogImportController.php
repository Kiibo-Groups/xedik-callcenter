<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telephony\QueueLogImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelephonyQueueLogImportController extends Controller
{
    public function store(Request $request, QueueLogImportService $service): JsonResponse
    {
        $payload = $request->validate([
            'pbx_id' => ['required', 'integer', 'min:1'],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'linkedid' => ['required', 'string', 'max:80'],
            'events' => ['required', 'array', 'min:1'],
            'events.*.id' => ['required', 'integer', 'min:1'],
            'events.*.time' => ['required', 'date'],
            'events.*.queuename' => ['nullable', 'string', 'max:120'],
            'events.*.agent' => ['nullable', 'string', 'max:120'],
            'events.*.event' => ['required', 'string', 'max:80'],
            'events.*.data1' => ['nullable', 'string', 'max:255'],
            'events.*.data2' => ['nullable', 'string', 'max:255'],
            'events.*.data3' => ['nullable', 'string', 'max:255'],
            'events.*.data4' => ['nullable', 'string', 'max:255'],
            'events.*.data5' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $service->import($payload);

        return response()->json([
            'ok' => true,
            'linkedid' => $payload['linkedid'],
            'events_imported' => $result['events_imported'],
            'call_upserted' => $result['call_upserted'],
        ]);
    }
}
