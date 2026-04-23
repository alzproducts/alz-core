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
        $token = self::requireStringField($response, 'Token');
        $server = self::requireStringField($response, 'Server');

        // Linnworks TTL is typically 24 hours; default if not provided.
        $ttl = $response['TTL'] ?? null;
        $ttl = \is_int($ttl) ? $ttl : 86400;

        $effectiveTtl = \max(1, $ttl - $ttlBuffer);
        $expiresAt = new DateTimeImmutable()->modify("+{$effectiveTtl} seconds");

        return new self(token: $token, serverUrl: $server, expiresAt: $expiresAt);
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function requireStringField(array $response, string $key): string
    {
        $value = $response[$key] ?? null;

        if (!\is_string($value) || ($value === '')) {
            throw new InvalidArgumentException("Auth response missing valid {$key}");
        }

        return $value;
    }
}
