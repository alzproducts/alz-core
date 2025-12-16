<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\InvalidConfigurationException;
use InvalidArgumentException;

/**
 * Immutable configuration for Linnworks API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Linnworks API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class LinnworksConfig
{
    /**
     * Linnworks authentication endpoint URL.
     */
    public const string AUTH_URL = 'https://api.linnworks.net/api/Auth/AuthorizeByApplication';

    /**
     * Maximum allowed timeout in seconds.
     */
    private const int MAX_TIMEOUT_SECONDS = 300;

    /**
     * Maximum allowed cache TTL buffer in seconds.
     */
    private const int MAX_CACHE_TTL_BUFFER = 3600;

    /**
     * @param string $applicationId Linnworks OAuth application ID
     * @param string $applicationSecret Linnworks OAuth application secret
     * @param string $installationToken User's installation/access token
     * @param int $timeout Request timeout in seconds (1-300)
     * @param int $cacheTtlBuffer Seconds to subtract from session TTL as safety margin (0-3600)
     *
     * @throws InvalidConfigurationException When required credentials are empty
     * @throws InvalidArgumentException When numeric parameters are out of bounds
     */
    public function __construct(
        public string $applicationId,
        public string $applicationSecret,
        public string $installationToken,
        public int $timeout = 30,
        public int $cacheTtlBuffer = 300,
    ) {
        if ($applicationId === '') {
            throw new InvalidConfigurationException('linnworks.application_id', 'Linnworks application ID cannot be empty');
        }

        if ($applicationSecret === '') {
            throw new InvalidConfigurationException('linnworks.application_secret', 'Linnworks application secret cannot be empty');
        }

        if ($installationToken === '') {
            throw new InvalidConfigurationException('linnworks.installation_token', 'Linnworks installation token cannot be empty');
        }

        if ($timeout < 1 || $timeout > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(
                \sprintf('Timeout must be between 1-%d seconds, got %d', self::MAX_TIMEOUT_SECONDS, $timeout),
            );
        }

        if ($cacheTtlBuffer < 0 || $cacheTtlBuffer > self::MAX_CACHE_TTL_BUFFER) {
            throw new InvalidArgumentException(
                \sprintf('Cache TTL buffer must be between 0-%d seconds, got %d', self::MAX_CACHE_TTL_BUFFER, $cacheTtlBuffer),
            );
        }
    }
}
