<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Factory for creating MixpanelClient with all dependencies.
 *
 * Follows the template pattern: Config → Transport → Client.
 * Validates configuration at boot time (fail-fast).
 */
final class MixpanelClientFactory
{
    public static function create(): MixpanelClientInterface
    {
        $config = self::createConfig();
        $transport = new MixpanelHttpTransport($config);

        return new MixpanelClient($transport, $config);
    }

    /**
     * Create config from Laravel configuration.
     *
     * Validates that environment variables are set before constructing.
     * MixpanelConfig handles domain validation (non-empty, boundary checks).
     *
     * @throws InvalidConfigurationException When required config values are missing or invalid
     */
    private static function createConfig(): MixpanelConfig
    {
        return new MixpanelConfig(
            dataApiBaseUrl: self::requireString(\config('mixpanel.base_url'), 'MIXPANEL_BASE_URL'),
            serviceAccountUsername: self::requireString(\config('mixpanel.service_account_username'), 'MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
            serviceAccountPassword: self::requireString(\config('mixpanel.service_account_password'), 'MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),
            projectId: self::requireString(\config('mixpanel.project_id'), 'MIXPANEL_PROJECT_ID'),
            lookupTableIds: self::requireLookupTables(\config('mixpanel.lookup_tables')),
            timeout: self::requireInt(\config('mixpanel.timeout'), 'MIXPANEL_TIMEOUT'),
            retryTimes: self::requireInt(\config('mixpanel.retry_times'), 'MIXPANEL_RETRY_TIMES'),
            retryDelay: self::requireInt(\config('mixpanel.retry_delay'), 'MIXPANEL_RETRY_DELAY'),
        );
    }

    /**
     * Validate that a config value is a string.
     *
     * @throws InvalidConfigurationException When value is not a string
     */
    private static function requireString(mixed $value, string $envVar): string
    {
        if (!\is_string($value)) {
            throw new InvalidConfigurationException($envVar);
        }

        return $value;
    }

    /**
     * Validate that a config value is an integer.
     *
     * @throws InvalidConfigurationException When value is not an integer
     */
    private static function requireInt(mixed $value, string $envVar): int
    {
        if (!\is_int($value)) {
            throw new InvalidConfigurationException($envVar, "{$envVar} must be an integer");
        }

        return $value;
    }

    /**
     * Validate and return lookup table IDs.
     *
     * @return array<string, string>
     *
     * @throws InvalidConfigurationException When lookup tables are invalid
     */
    private static function requireLookupTables(mixed $value): array
    {
        if (!\is_array($value) || $value === []) {
            throw new InvalidConfigurationException('mixpanel.lookup_tables', 'mixpanel.lookup_tables must be a non-empty array');
        }

        foreach ($value as $key => $tableId) {
            if (!\is_string($tableId) || $tableId === '') {
                throw new InvalidConfigurationException("mixpanel.lookup_tables.{$key}", "Lookup table '{$key}' must be a non-empty string");
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }
}
