<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable configuration for Reviews.io API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Reviews.io API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class ReviewsIoConfig
{
    /**
     * Maximum SKUs per batch request (Reviews.io API limit).
     */
    public const int MAX_BATCH_SIZE = 100;

    /**
     * Maximum length for a single SKU (e-commerce standard).
     */
    public const int MAX_SKU_LENGTH = 100;

    /**
     * Delimiter used to separate SKUs in batch requests.
     */
    public const string SKU_DELIMITER = ';';

    /**
     * Default Reviews.io API base URL.
     */
    private const string DEFAULT_BASE_URL = 'https://api.reviews.co.uk/';

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
     * @param string $apiKey Reviews.io API key
     * @param string $storeId Reviews.io store identifier
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
        public string $storeId,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
        public int $retryTimes = 3,
        public int $retryDelay = 100,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Reviews.io API key cannot be empty');
        }

        if ($storeId === '') {
            throw new RuntimeException('Reviews.io store ID cannot be empty');
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
