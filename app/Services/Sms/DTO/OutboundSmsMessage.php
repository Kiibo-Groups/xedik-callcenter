<?php

declare(strict_types=1);

namespace App\Services\Sms\DTO;

final class OutboundSmsMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $body,
        public readonly ?int $campaignId = null,
        public readonly ?int $templateId = null,
        public readonly ?array $metadata = null,
    ) {}
}
