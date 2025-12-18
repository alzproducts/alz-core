<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\InvalidConfigurationException;
use InvalidArgumentException;

/**
 * Immutable configuration for Shopwired API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Shopwired API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * Note: Retry behavior is controlled by RetryStrategy enum, not config values.
 * This keeps retry policy decisions explicit at the call site.
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
     * @param string $apiKey Shopwired API key (HTTP Basic Auth username)
     * @param string $apiSecret Shopwired API secret (HTTP Basic Auth password)
     * @param string $baseUrl API base URL (defaults to production)
     * @param int $timeout Request timeout in seconds (1-300)
     *
     * @throws InvalidConfigurationException When required credentials are empty
     * @throws InvalidArgumentException When timeout is out of bounds
     */
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
    ) {
        if ($apiKey === '') {
            throw new InvalidConfigurationException('shopwired.api_key', 'Shopwired API key cannot be empty');
        }

        if ($apiSecret === '') {
            throw new InvalidConfigurationException('shopwired.api_secret', 'Shopwired API secret cannot be empty');
        }

        if (($timeout < 1) || ($timeout > self::MAX_TIMEOUT_SECONDS)) {
            throw new InvalidArgumentException(
                \sprintf('Timeout must be between 1-%d seconds, got %d', self::MAX_TIMEOUT_SECONDS, $timeout),
            );
        }
    }
}
