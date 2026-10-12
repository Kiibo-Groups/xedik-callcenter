<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class VoicePerformanceMeasurement
{
    private int $startedAt;

    /** @var array<string, float> */
    private array $durations = [];

    /** @var array<string, bool|float|int|string|null> */
    private array $context = [];

    public function __construct(
        public readonly string $operation,
        public readonly string $measurementId
    ) {
        $this->startedAt = hrtime(true);
    }

    public static function create(
        string $operation,
        ?string $requestedId
    ): self {
        $measurementId = self::validatedId($requestedId)
            ?? (string) Str::uuid();

        return new self($operation, $measurementId);
    }

    public static function validatedId(?string $requestedId): ?string
    {
        return is_string($requestedId)
            && preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/',
                $requestedId
            ) === 1
                ? $requestedId
                : null;
    }

    public static function capture(
        ?self $measurement,
        string $metric,
        Closure $callback
    ): mixed {
        if (!$measurement) {
            return $callback();
        }

        return $measurement->measure($metric, $callback);
    }

    public function measure(string $metric, Closure $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->addDuration(
                $metric,
                self::elapsedMilliseconds($startedAt)
            );
        }
    }

    public function recordSince(string $metric, int $startedAt): void
    {
        $this->addDuration(
            $metric,
            self::elapsedMilliseconds($startedAt)
        );
    }

    public function recordTotal(string $metric): void
    {
        $this->addDuration(
            $metric,
            self::elapsedMilliseconds($this->startedAt)
        );
    }

    public function recordDuration(string $metric, float $duration): void
    {
        $this->addDuration($metric, max(0.0, $duration));
    }

    /** @param array<string, bool|float|int|string|null> $context */
    public function addContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /** @return array<string, float> */
    public function durations(): array
    {
        return array_map(
            static fn (float $duration): float => round($duration, 2),
            $this->durations
        );
    }

    public function serverTiming(): string
    {
        $metrics = $this->operation === 'registered'
            ? [
                'registered_service' =>
                    $this->durations['registered_service_total_ms']
                        ?? null,
                'registered_lookup' =>
                    $this->durations['registered_session_lookup_ms']
                        ?? null,
                'registered_update' =>
                    $this->durations['registered_session_update_ms']
                        ?? null,
            ]
            : [
                'voice_service' =>
                    $this->durations['service_total_ms'] ?? null,
                'sip_rotate' =>
                    $this->durations['credential_rotation_total_ms']
                        ?? null,
                'realtime' =>
                    $this->durations['asterisk_realtime_update_ms']
                        ?? null,
            ];

        $values = [];

        foreach ($metrics as $name => $duration) {
            if ($duration !== null) {
                $values[] = sprintf('%s;dur=%.2f', $name, $duration);
            }
        }

        return implode(', ', $values);
    }

    /** @param array<string, bool|float|int|string|null> $context */
    public function writeLog(array $context): void
    {
        Log::info('voice.performance', array_merge([
            'operation' => $this->operation,
            'measurement_id' => $this->measurementId,
        ], $context, $this->context, $this->durations()));
    }

    private function addDuration(string $metric, float $duration): void
    {
        $this->durations[$metric] =
            ($this->durations[$metric] ?? 0.0) + $duration;
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
