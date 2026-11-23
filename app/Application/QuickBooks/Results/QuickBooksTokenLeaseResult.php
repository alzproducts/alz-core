<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Results;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use DateTimeImmutable;

/**
 * An access token handed to another process, without the refresh token behind it.
 */
final readonly class QuickBooksTokenLeaseResult
{
    public function __construct(
        public ApiKeyToken $accessToken,
        public DateTimeImmutable $expiresAt,
    ) {}
}
