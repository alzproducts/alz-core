<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable session value object for Linnworks API authentication.
 *
 * Represents a valid, time-bounded session containing the authentication
 * token and region-specific server URL. Sessions are cached in Redis
 * and automatically refreshed before expiry.
 *
 * @template-pattern API Session Value Object
 */
final readonly class LinnworksSession
{
    /**
     * @param string $token Bearer authentication token
     * @param string $serverUrl Region-specific API server URL (e.g., https://eu-ext.linnworks.net)
     * @param DateTimeImmutable $expiresAt When this session expires
     *
     * @throws InvalidArgumentException When token or serverUrl is empty
     */
    public function __construct(
        public string $token,
        public string $serverUrl,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('Session token cannot be empty');
        }

        if ($serverUrl === '') {
            throw new InvalidArgumentException('Server URL cannot be empty');
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
     * Create session from Linnworks auth endpoint response.
     *
     * @param array<string, mixed> $response Auth endpoint response (untrusted external data)
     * @param int $ttlBuffer Seconds to subtract from TTL as safety margin
     *
     * @throws InvalidArgumentException|DateMalformedStringException When response structure is invalid
     */
    public static function fromAuthResponse(array $response, int $ttlBuffer): self
    {
        $token = $response['Token'] ?? null;
        $server = $response['Server'] ?? null;

        if (!\is_string($token) || ($token === '')) {
            throw new InvalidArgumentException('Auth response missing valid Token');
        }

        if (!\is_string($server) || ($server === '')) {
            throw new InvalidArgumentException('Auth response missing valid Server');
        }

        // Linnworks TTL is typically 24 hours (86400 seconds)
        // Default to 24 hours if not provided
        $ttl = $response['TTL'] ?? null;
        $ttl = \is_int($ttl) ? $ttl : 86400;

        // Apply buffer to refresh session before actual expiry
        $effectiveTtl = \max(1, $ttl - $ttlBuffer);

        $expiresAt = new DateTimeImmutable()->modify("+{$effectiveTtl} seconds");

        return new self(
            token: $token,
            serverUrl: $server,
            expiresAt: $expiresAt,
        );
    }
}
