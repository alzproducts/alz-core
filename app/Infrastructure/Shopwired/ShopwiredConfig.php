<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable configuration for Shopwired API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Shopwired API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class ShopwiredConfig
{
    /**
     * Default Shopwired API base URL.
     */
    public const string DEFAULT_BASE_URL = 'https://api.ecommerceapi.uk/v1';

    /**
     * Maximum allowed timeout in seconds.
     */
    private const int MAX_TIMEOUT_SECONDS = 300;

    /**
     * Maximum retry attempts allowed.
     */
    private const int MAX_RETRY_ATTEMPTS = 10;

    /**
     * Maximum retry delay in milliseconds.
     */
    private const int MAX_RETRY_DELAY_MS = 5000;

    /**
     * @param string $apiKey Shopwired API key (HTTP Basic Auth username)
     * @param string $apiSecret Shopwired API secret (HTTP Basic Auth password)
     * @param string $baseUrl API base URL (defaults to production)
     * @param int $timeout Request timeout in seconds (1-300)
     * @param int $retryTimes Number of retry attempts (0-10)
     * @param int $retryDelay Delay between retries in milliseconds (0-5000)
     *
     * @throws RuntimeException When required credentials are empty
     * @throws InvalidArgumentException When numeric parameters are out of bounds
     */
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
        public int $retryTimes = 3,
        public int $retryDelay = 100,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Shopwired API key cannot be empty');
        }

        if ($apiSecret === '') {
            throw new RuntimeException('Shopwired API secret cannot be empty');
        }

        if (($timeout < 1) || ($timeout > self::MAX_TIMEOUT_SECONDS)) {
            throw new InvalidArgumentException(
                \sprintf('Timeout must be between 1-%d seconds, got %d', self::MAX_TIMEOUT_SECONDS, $timeout),
            );
        }

        if (($retryTimes < 0) || ($retryTimes > self::MAX_RETRY_ATTEMPTS)) {
            throw new InvalidArgumentException(
                \sprintf('Retry times must be between 0-%d, got %d', self::MAX_RETRY_ATTEMPTS, $retryTimes),
            );
        }

        if (($retryDelay < 0) || ($retryDelay > self::MAX_RETRY_DELAY_MS)) {
            throw new InvalidArgumentException(
                \sprintf('Retry delay must be between 0-%dms, got %d', self::MAX_RETRY_DELAY_MS, $retryDelay),
            );
        }
    }
}
