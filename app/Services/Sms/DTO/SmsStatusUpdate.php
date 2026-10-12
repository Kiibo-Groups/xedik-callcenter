<?php

declare(strict_types=1);

namespace App\Services\Sms\DTO;

use Illuminate\Support\Carbon;

final class SmsStatusUpdate
{
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly ?string $providerStatus,
        public readonly ?string $errorCode,
        public readonly ?int $segments,
        public readonly ?float $cost,
        public readonly ?Carbon $startedAt,
        public readonly ?Carbon $completedAt,
        public readonly array $raw = [],
    ) {}
}
