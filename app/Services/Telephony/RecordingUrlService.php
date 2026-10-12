<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\TelephonyCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

class RecordingUrlService
{
    public const MODE_INLINE = 'inline';
    public const MODE_ATTACHMENT = 'attachment';

    /** @return array{url: string, expires_at: int}|null */
    public function inlineUrl(TelephonyCall $call): ?array
    {
        return $this->temporaryUrl($call, self::MODE_INLINE);
    }

    /** @return array{url: string, expires_at: int}|null */
    public function attachmentUrl(TelephonyCall $call): ?array
    {
        return $this->temporaryUrl($call, self::MODE_ATTACHMENT);
    }

    public function isAvailable(TelephonyCall $call): bool
    {
        return $this->parametersFor($call) !== null;
    }

    /** @return array{url: string, expires_at: int}|null */
    private function temporaryUrl(TelephonyCall $call, string $mode): ?array
    {
        if (!in_array($mode, [self::MODE_INLINE, self::MODE_ATTACHMENT], true)) {
            throw new InvalidArgumentException('Invalid recording mode.');
        }

        $params = $this->parametersFor($call);
        if ($params === null) {
            return null;
        }

        $baseUrl = trim((string) config('voice.recordings.base_url', ''));
        $secret = (string) config('voice.recordings.shared_secret', '');
        $ttl = max(1, (int) config('voice.recordings.url_ttl', 120));

        if ($baseUrl === '' || $secret === '') {
            throw new RuntimeException('Recording service is not configured.');
        }

        $expires = Carbon::now()->timestamp + $ttl;
        $signed = array_merge($params, [
            'mode' => $mode,
            'expires' => (string) $expires,
        ]);
        $signed['signature'] = $this->signature($signed, $secret);

        return [
            'url' => $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($signed, '', '&', PHP_QUERY_RFC3986),
            'expires_at' => $expires,
        ];
    }

    /** @return array{tenant_id: string, direction: string, date: string, linkedid: string}|null */
    private function parametersFor(TelephonyCall $call): ?array
    {
        $tenantId = (int) $call->tenant_id;
        $linkedid = trim((string) $call->linkedid);
        $direction = $this->direction($call);
        $startedAt = $call->started_at;

        if ($tenantId <= 0 || $direction === null || $linkedid === '' || !$startedAt instanceof CarbonInterface) {
            return null;
        }

        if ($direction === 'in' && strtoupper((string) $call->exit_reason) === 'ABANDON') {
            return null;
        }

        if (!preg_match('/\A[0-9]+(?:[.-][0-9]+)*\z/', $linkedid)) {
            return null;
        }

        return [
            'tenant_id' => (string) $tenantId,
            'direction' => $direction,
            'date' => $startedAt->format('Y-m-d'),
            'linkedid' => $linkedid,
        ];
    }

    private function direction(TelephonyCall $call): ?string
    {
        return match (strtoupper((string) $call->direction)) {
            'INBOUND' => 'in',
            'OUTBOUND' => 'out',
            default => null,
        };
    }

    /** @param array{tenant_id: string, direction: string, date: string, linkedid: string, mode: string, expires: string} $params */
    private function signature(array $params, string $secret): string
    {
        $payload = implode('|', [
            $params['tenant_id'],
            $params['direction'],
            $params['date'],
            $params['linkedid'],
            $params['mode'],
            $params['expires'],
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
