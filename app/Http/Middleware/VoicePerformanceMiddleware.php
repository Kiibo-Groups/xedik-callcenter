<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Voice\VoicePerformanceMeasurement;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class VoicePerformanceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $receivedAt = (float) (
            $request->server('REQUEST_TIME_FLOAT') ?: microtime(true)
        );
        $requestId = (string) Str::uuid();
        $measurementId = VoicePerformanceMeasurement::validatedId(
            $request->header('X-Voice-Measurement-ID')
        );
        $operation = $this->operation($request);
        $browserTabId = $this->safeBrowserTabId(
            $request->input('browser_tab_id')
        );
        $sessionUuid = $this->sessionUuid($request);
        $user = $request->user();
        $controllerEntryMs = max(
            0.0,
            (microtime(true) - $receivedAt) * 1000
        );

        $request->attributes->set(
            'voice_performance_request_id',
            $requestId
        );
        $request->attributes->set(
            'voice_performance_received_at',
            $receivedAt
        );
        $request->attributes->set(
            'voice_performance_controller_entry_ms',
            $controllerEntryMs
        );

        $context = [
            'request_id' => $requestId,
            'measurement_id' => $measurementId,
            'operation' => $operation,
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'session_uuid' => $sessionUuid,
            'browser_tab_id' => $browserTabId,
            'started_at' => $this->timestamp($receivedAt),
            'route_name' => $request->route()?->getName(),
            'method' => $request->getMethod(),
            'request_received_to_controller_ms' =>
                round($controllerEntryMs, 2),
        ];

        $request->attributes->set(
            'voice_performance_request_context',
            $context
        );

        Log::info('voice.request.started', $context);

        $controllerStartedAt = hrtime(true);
        $response = $next($request);

        if (!$response->headers->has(
            'X-Voice-Controller-Duration-Ms'
        )) {
            $response->headers->set(
                'X-Voice-Controller-Duration-Ms',
                number_format(
                    (hrtime(true) - $controllerStartedAt) / 1_000_000,
                    2,
                    '.',
                    ''
                )
            );
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $context = $request->attributes->get(
            'voice_performance_request_context'
        );

        if (!is_array($context)) {
            return;
        }

        $receivedAt = (float) $request->attributes->get(
            'voice_performance_received_at',
            microtime(true)
        );
        $requestTotalMs = max(
            0.0,
            (microtime(true) - $receivedAt) * 1000
        );
        $controllerTotalMs = $this->numericHeader(
            $response,
            'X-Voice-Controller-Duration-Ms'
        );
        $controllerEntryMs = (float) $request->attributes->get(
            'voice_performance_controller_entry_ms',
            0.0
        );
        $unaccountedMs = $controllerTotalMs === null
            ? null
            : max(0.0, $requestTotalMs - $controllerTotalMs);
        $afterControllerMs = $controllerTotalMs === null
            ? null
            : max(
                0.0,
                $requestTotalMs
                    - $controllerEntryMs
                    - $controllerTotalMs
            );

        Log::info('voice.request.completed', array_merge($context, [
            'completed_at' => $this->timestamp(microtime(true)),
            'duration_ms' => round($requestTotalMs, 2),
            'middleware_total_ms' => round($requestTotalMs, 2),
            'controller_total_ms' => $controllerTotalMs,
            'controller_to_response_sent_ms' =>
                $afterControllerMs === null
                    ? null
                    : round($afterControllerMs, 2),
            'unaccounted_inside_server_ms' =>
                $unaccountedMs === null
                    ? null
                    : round($unaccountedMs, 2),
            'status_code' => $response->getStatusCode(),
        ]));
    }

    private function operation(Request $request): string
    {
        return match ($request->route()?->getName()) {
            'voice.session.take-over' => 'takeover',
            'voice.session.ownership' => 'ownership',
            'voice.session.start' => 'start',
            'voice.session.reconnect' => 'reconnect',
            'voice.session.registered' => 'registered',
            'voice.session.heartbeat' => 'heartbeat',
            'voice.session.revoke' => 'revoke',
            default => 'unknown',
        };
    }

    private function sessionUuid(Request $request): ?string
    {
        $candidate = $request->route('sessionUuid')
            ?? $request->input('session_uuid');

        return is_string($candidate) && Str::isUuid($candidate)
            ? $candidate
            : null;
    }

    private function safeBrowserTabId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return $value === null || $value === ''
            ? null
            : Str::limit($value, 120, '');
    }

    private function numericHeader(
        Response $response,
        string $header
    ): ?float {
        $value = $response->headers->get($header);

        return is_string($value) && is_numeric($value)
            ? round((float) $value, 2)
            : null;
    }

    private function timestamp(float $timestamp): string
    {
        $date = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6F', $timestamp)
        );

        return $date
            ? $date->format('Y-m-d\TH:i:s.u\Z')
            : gmdate('Y-m-d\TH:i:s\Z');
    }
}
