<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use Throwable;

final class ApiRateLimitException extends ApiException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter = 60,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
