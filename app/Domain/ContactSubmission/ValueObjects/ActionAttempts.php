<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * A contact action's attempt count after its claim; the row's only retry budget (ADR-0012).
 */
final readonly class ActionAttempts
{
    public const int LIMIT = 5;

    public function __construct(public int $count)
    {
        Assert::positiveInteger($count, 'Action attempt count must be at least 1');
    }

    /**
     * Seconds until the next attempt: the API's Retry-After when given, else a backoff by count.
     */
    public function retryDelay(?int $retryAfter): int
    {
        return $retryAfter ?? match ($this->count) {
            1 => 60,
            2 => 300,
            3 => 3600,
            default => 43200,
        };
    }

    public function isPastLimit(): bool
    {
        return $this->count > self::LIMIT;
    }

    public function isLast(): bool
    {
        return $this->count === self::LIMIT;
    }
}
