<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable session value object for Bing Ads API authentication.
 *
 * Represents a valid, time-bounded session containing the OAuth2 access token.
 * Sessions are cached in Redis and automatically refreshed before expiry.
 *
 * This is a pure value object - OAuth response parsing and TTL policy
 * are handled by BingAdsSessionManager at the boundary.
 *
 * @template-pattern API Session Value Object
 */
final readonly class BingAdsSession
{
    /**
     * @param string $accessToken OAuth2 access token for API requests
     * @param DateTimeImmutable $expiresAt When this session expires
     *
     * @throws InvalidArgumentException When access token is empty
     */
    public function __construct(
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($accessToken === '') {
            throw new InvalidArgumentException('Access token cannot be empty');
        }
    }

    /**
     * Check if this session has expired.
     *
     * Always calculates fresh timestamp (Octane-safe - no stale values).
     */
    public function isExpired(): bool
    {
        return new DateTimeImmutable() >= $this->expiresAt;
    }
}
