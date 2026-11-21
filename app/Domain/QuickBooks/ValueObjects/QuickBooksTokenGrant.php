<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\ValueObjects;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * One Intuit token response: a short-lived access token and the (possibly rotated) QBO connection.
 */
final readonly class QuickBooksTokenGrant
{
    public function __construct(
        public ApiKeyToken $accessToken,
        public DateTimeImmutable $accessTokenExpiresAt,
        public QuickBooksConnection $connection,
    ) {
        Assert::true(
            $accessTokenExpiresAt <= $connection->refreshTokenExpiresAt,
            'An access token cannot outlive the refresh token it was granted with',
        );
    }
}
