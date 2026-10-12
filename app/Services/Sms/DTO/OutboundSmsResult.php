<?php

declare(strict_types=1);

namespace App\Services\Sms\DTO;

final class OutboundSmsResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorMessage,
        public readonly array $raw = [],
    ) {}
}
