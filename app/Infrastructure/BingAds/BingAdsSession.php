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

    /**
     * Create session from Microsoft OAuth2 token response.
     *
     * @param array<string, mixed> $response OAuth2 token response (untrusted external data)
     * @param int $ttlBuffer Seconds to subtract from TTL as safety margin
     *
     * @throws InvalidArgumentException When response structure is invalid
     */
    public static function fromOAuthResponse(array $response, int $ttlBuffer = 60): self
    {
        $accessToken = $response['access_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (!\is_string($accessToken) || ($accessToken === '')) {
            throw new InvalidArgumentException('OAuth response missing valid access_token');
        }

        if (!\is_int($expiresIn) || ($expiresIn <= 0)) {
            throw new InvalidArgumentException('OAuth response missing valid expires_in');
        }

        // Apply buffer to refresh session before actual expiry
        $effectiveTtl = \max(1, $expiresIn - $ttlBuffer);

        $expiresAt = new DateTimeImmutable("+{$effectiveTtl} seconds");

        return new self(
            accessToken: $accessToken,
            expiresAt: $expiresAt,
        );
    }
}
