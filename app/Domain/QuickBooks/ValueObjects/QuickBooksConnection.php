<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\ValueObjects;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use DateTimeImmutable;

/**
 * The stored QBO connection for the configured QBO environment: the long-lived refresh token and its expiries.
 */
final readonly class QuickBooksConnection
{
    public function __construct(
        public ApiKeyToken $refreshToken,
        public DateTimeImmutable $refreshTokenExpiresAt,
        public ?DateTimeImmutable $refreshTokenHardExpiresAt,
    ) {}
}
