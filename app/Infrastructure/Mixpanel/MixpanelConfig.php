<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable configuration for Mixpanel API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Mixpanel API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * Mixpanel uses two base URLs:
 * - Main API (mixpanel.com): Authentication and account endpoints
 * - Data API (api-eu.mixpanel.com): Event ingestion and lookup tables
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class MixpanelConfig
{
    /**
     * Mixpanel main API base URL for authentication verification.
     */
    public const string MAIN_API_URL = 'https://mixpanel.com';

    /**
     * Default Mixpanel Data API base URL.
     */
    public const string DEFAULT_DATA_API_URL = 'https://api-eu.mixpanel.com';

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
     * @param string $dataApiBaseUrl Data API base URL (defaults to production)
     * @param string $serviceAccountUsername Service account username for Basic Auth
     * @param string $serviceAccountPassword Service account password for Basic Auth
     * @param string $projectId Mixpanel project identifier
     * @param array<string, string> $lookupTableIds Lookup table identifiers keyed by name
     * @param int $timeout Request timeout in seconds (1-300)
     * @param int $retryTimes Number of retry attempts (0-10)
     * @param int $retryDelay Delay between retries in milliseconds (0-5000)
     *
     * @throws RuntimeException When required credentials are empty
     * @throws InvalidArgumentException When numeric parameters are out of bounds
     */
    public function __construct(
        public string $dataApiBaseUrl,
        public string $serviceAccountUsername,
        public string $serviceAccountPassword,
        public string $projectId,
        public array $lookupTableIds,
        public int $timeout = 30,
        public int $retryTimes = 3,
        public int $retryDelay = 100,
    ) {
        if ($dataApiBaseUrl === '') {
            throw new RuntimeException('Mixpanel data API base URL cannot be empty');
        }

        if ($serviceAccountUsername === '') {
            throw new RuntimeException('Mixpanel service account username cannot be empty');
        }

        if ($serviceAccountPassword === '') {
            throw new RuntimeException('Mixpanel service account password cannot be empty');
        }

        if ($projectId === '') {
            throw new RuntimeException('Mixpanel project ID cannot be empty');
        }

        // Note: lookupTableIds validated by MixpanelClientFactory (type + non-empty)

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
