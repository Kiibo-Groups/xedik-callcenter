<?php

declare(strict_types=1);

namespace App\Services\Sms\Providers;

use App\Models\SmsChannel;
use App\Models\SmsProviderAccount;
use App\Services\Sms\Contracts\SmsProviderInterface;
use App\Services\Sms\DTO\InboundSmsMessage;
use App\Services\Sms\DTO\OutboundSmsMessage;
use App\Services\Sms\DTO\OutboundSmsResult;
use App\Services\Sms\DTO\SmsStatusUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Único adaptador SMS del MVP. Traduce el formato específico de DIDWW hacia/desde
 * los DTOs genéricos — SmsService y el resto del sistema no conocen esta clase.
 */
class DidwwSmsProvider implements SmsProviderInterface
{
    public static function providerKey(): string
    {
        return 'didww';
    }

    public static function label(): string
    {
        return 'DIDWW';
    }

    public function send(SmsChannel $channel, SmsProviderAccount $account, OutboundSmsMessage $message): OutboundSmsResult
    {
        $credentials = $account->credentials ?? [];
        $outboundUrl = $account->settings['outbound_url'] ?? null;
        $username    = $credentials['username'] ?? null;
        $password    = $credentials['password'] ?? null;

        if (!$outboundUrl || !$username || !$password) {
            return new OutboundSmsResult(
                success: false,
                providerMessageId: null,
                errorMessage: 'DIDWW account misconfigured: missing outbound_url/username/password.',
            );
        }

        try {
            $response = Http::withBasicAuth($username, $password)
                ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
                ->timeout(15)
                ->post($outboundUrl, [
                    'data' => [
                        'type'       => 'outbound_messages',
                        'attributes' => [
                            'content'     => $message->body,
                            'destination' => $message->to,
                            'source'      => $message->from,
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            return new OutboundSmsResult(false, null, 'DIDWW request failed: ' . $e->getMessage());
        }

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            return new OutboundSmsResult(false, null, 'DIDWW API error (' . $response->status() . '): ' . $response->body(), $json);
        }

        return new OutboundSmsResult(true, $json['data']['id'] ?? null, null, $json);
    }

    public function parseInbound(array $payload, ?SmsProviderAccount $account = null): InboundSmsMessage
    {
        $to   = (string) ($payload['to'] ?? '');
        $from = (string) ($payload['from'] ?? '');

        $receivedAt = !empty($payload['received_at'])
            ? Carbon::parse($payload['received_at'])->utc()
            : Carbon::now('UTC');

        $body = null;
        if (!empty($payload['message_base64'])) {
            $decoded = base64_decode((string) $payload['message_base64'], true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }
        if ($body === null) {
            $body = (string) ($payload['message'] ?? '');
        }

        // DIDWW no entrega un id único para mensajes inbound — se genera un hash
        // determinístico del payload para poder deduplicar reintentos del webhook.
        $dedupSeed = $to . '|' . $from . '|' . ($payload['message_base64'] ?? $payload['message'] ?? '') . '|' . ($payload['received_at'] ?? '');

        return new InboundSmsMessage(
            to: $to,
            from: $from,
            body: $body,
            receivedAt: $receivedAt,
            providerMessageId: 'didww_in_' . sha1($dedupSeed),
            raw: $payload,
        );
    }

    public function parseStatusCallback(array $payload, ?SmsProviderAccount $account = null): SmsStatusUpdate
    {
        $data  = $payload['data'] ?? [];
        $attrs = $data['attributes'] ?? [];

        $rawStatus = (string) ($attrs['status'] ?? '');
        $status    = match (true) {
            $rawStatus === ''                              => null,
            strcasecmp($rawStatus, 'Success') === 0         => 'sent', // no confundir con "delivered" — DIDWW no confirma entrega en este evento
            default                                         => strtolower($rawStatus),
        };

        return new SmsStatusUpdate(
            providerMessageId: $data['id'] ?? null,
            providerStatus: $status,
            errorCode: isset($attrs['code_id']) ? (string) $attrs['code_id'] : null,
            segments: isset($attrs['fragments_sent']) ? (int) $attrs['fragments_sent'] : null,
            cost: isset($attrs['price']) ? (float) $attrs['price'] : null,
            startedAt: !empty($attrs['time_start']) ? Carbon::parse($attrs['time_start'])->utc() : null,
            completedAt: !empty($attrs['end_time']) ? Carbon::parse($attrs['end_time'])->utc() : null,
            raw: $payload,
        );
    }
}
