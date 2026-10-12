<?php

declare(strict_types=1);

namespace App\Services\Sms\DTO;

use Illuminate\Support\Carbon;

final class InboundSmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $from,
        public readonly string $body,
        public readonly Carbon $receivedAt,
        public readonly ?string $providerMessageId,
        public readonly array $raw = [],
    ) {}
}
