<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class ApiRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter = 60,
    ) {
        parent::__construct($message);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
