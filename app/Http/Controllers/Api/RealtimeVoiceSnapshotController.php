<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telephony\RealtimeVoiceSnapshotImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

class RealtimeVoiceSnapshotController extends Controller
{
    public function __construct(
        private readonly RealtimeVoiceSnapshotImportService $importService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if (!Config::get('realtime_monitor.enabled', false)) {
            return response()->json(['ok' => false, 'message' => 'Realtime monitor disabled.'], 503);
        }

        $rawBody = $request->getContent();
        $maxBytes = (int) Config::get('realtime_monitor.max_payload_bytes', 262144);

        if (strlen($rawBody) > $maxBytes) {
            return response()->json(['ok' => false, 'message' => 'Payload too large.'], 413);
        }

        if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            return response()->json(['ok' => false, 'message' => 'Content-Type must be application/json.'], 415);
        }

        $auth = $this->validateSignature($request, $rawBody);
        if ($auth !== null) {
            return $auth;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['ok' => false, 'message' => 'Invalid JSON body.'], 400);
        }

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'string'],
            'pbx_id' => ['required', 'integer', 'min:1'],
            'generated_at' => ['required', 'date'],
            'sequence' => ['required', 'integer', 'min:1'],
            'queues' => ['present', 'array'],
            'members' => ['present', 'array'],
            'waiting_calls' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'message' => 'Invalid snapshot payload.'], 422);
        }

        if (!in_array($payload['schema_version'], Config::get('realtime_monitor.supported_schema_versions', ['1.0']), true)) {
            return response()->json(['ok' => false, 'message' => 'Unsupported schema_version.'], 422);
        }

        $pbxId = (int) $payload['pbx_id'];
        if ((string) $request->headers->get('X-XEDIK-PBX') !== (string) $pbxId) {
            return response()->json(['ok' => false, 'message' => 'PBX header mismatch.'], 403);
        }

        $configuredPbxId = Config::get('voice.pbx_id');
        if ($configuredPbxId === null || $configuredPbxId === '' || (int) $configuredPbxId !== $pbxId) {
            return response()->json(['ok' => false, 'message' => 'Unknown PBX.'], 404);
        }

        $processed = $this->importService->import($payload, $rawBody);

        return response()->json([
            'ok' => true,
            'sequence' => (int) $payload['sequence'],
            'processed' => $processed,
        ]);
    }

    private function validateSignature(Request $request, string $rawBody): ?JsonResponse
    {
        $secret = (string) Config::get('realtime_monitor.shared_secret', '');
        if ($secret === '') {
            return response()->json(['ok' => false, 'message' => 'Realtime monitor secret is not configured.'], 503);
        }

        $timestamp = (string) $request->headers->get('X-XEDIK-Timestamp', '');
        $signature = (string) $request->headers->get('X-XEDIK-Signature', '');

        if ($timestamp === '' || $signature === '') {
            return response()->json(['ok' => false, 'message' => 'Missing signature headers.'], 403);
        }

        if (!ctype_digit($timestamp)) {
            return response()->json(['ok' => false, 'message' => 'Invalid timestamp.'], 403);
        }

        $tolerance = (int) Config::get('realtime_monitor.timestamp_tolerance', 60);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return response()->json(['ok' => false, 'message' => 'Expired timestamp.'], 403);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            return response()->json(['ok' => false, 'message' => 'Invalid signature.'], 403);
        }

        $replayKey = 'realtime-monitor-signature:' . hash('sha256', $request->headers->get('X-XEDIK-PBX', '') . '|' . $timestamp . '|' . $signature);
        if (!Cache::add($replayKey, true, now()->addSeconds(max($tolerance, 1)))) {
            return response()->json(['ok' => false, 'message' => 'Replay rejected.'], 409);
        }

        return null;
    }
}
