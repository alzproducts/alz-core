<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

use Throwable;

final class ApiRateLimitException extends ApiException
{
    public readonly string $detail;

    public function __construct(
        string $message,
        private readonly int $retryAfter = 60,
        ?Throwable $previous = null,
    ) {
        $this->detail = $message;
        parent::__construct('API rate limit exceeded', 0, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function context(): array
    {
        return ['retry_after' => $this->retryAfter, 'detail' => $this->detail];
    }
}
