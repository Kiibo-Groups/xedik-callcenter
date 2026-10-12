<?php

declare(strict_types=1);

namespace App\Services;

class PreviewDialerOfferException extends \RuntimeException
{
    public function __construct(public readonly string $error, public readonly int $statusCode = 422)
    {
        parent::__construct($error);
    }
}
