<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel;

use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Mixpanel\Contracts\MixpanelTransportInterface;
use App\Infrastructure\Mixpanel\Enums\MixpanelLogLevel;
use App\Infrastructure\Support\TransientLogThrottle;

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
        $transport = self::createTransport($config);

        return new MixpanelClient($transport, $config);
    }

    /**
     * Create transport with optional logging decorator.
     *
     * @throws InvalidConfigurationException When log level is invalid
     */
    private static function createTransport(MixpanelConfig $config): MixpanelTransportInterface
    {
        $logLevel = \config('mixpanel.log_level');

        $logThrottle = \app(TransientLogThrottle::class);

        if (!\is_string($logLevel) || $logLevel === '') {
            return new MixpanelHttpTransport($config, $logThrottle);
        }

        $parsed = MixpanelLogLevel::tryFrom($logLevel);

        if ($parsed === null) {
            throw new InvalidConfigurationException(
                'MIXPANEL_LOG_LEVEL',
                "Invalid value '{$logLevel}'. Must be 'info' or 'debug'.",
            );
        }

        return new LoggingMixpanelTransport(new MixpanelHttpTransport($config, $logThrottle), $parsed);
    }

    /**
     * Create config from Laravel configuration.
     *
     * Validates that environment variables are set before constructing.
     * MixpanelConfig handles domain validation (non-empty, boundary checks).
     *
     * @throws InvalidConfigurationException When required config values are missing or invalid
     */
    public static function createConfig(): MixpanelConfig
    {
        return new MixpanelConfig(
            dataApiBaseUrl: self::requireString(\config('mixpanel.base_url'), 'MIXPANEL_BASE_URL'),
            exportApiBaseUrl: self::requireString(\config('mixpanel.export_api_base_url'), 'MIXPANEL_EXPORT_API_BASE_URL'),
            serviceAccountUsername: self::requireString(\config('mixpanel.service_account_username'), 'MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
            serviceAccountPassword: self::requireString(\config('mixpanel.service_account_password'), 'MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),
            projectId: self::requireString(\config('mixpanel.project_id'), 'MIXPANEL_PROJECT_ID'),
            analyticsSalt: self::requireString(\config('mixpanel.analytics_salt'), 'ANALYTICS_SALT'),
            lookupTableIds: self::requireLookupTables(\config('mixpanel.lookup_tables')),
            timeout: self::requireInt(\config('mixpanel.timeout'), 'MIXPANEL_TIMEOUT'),
            retryTimes: self::requireInt(\config('mixpanel.retry_times'), 'MIXPANEL_RETRY_TIMES'),
            retryDelay: self::requireInt(\config('mixpanel.retry_delay'), 'MIXPANEL_RETRY_DELAY'),
            allowEmptyExport: (bool) \config('mixpanel.allow_empty_export', false),
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
            if (!\is_string($key)) {
                throw new InvalidConfigurationException('mixpanel.lookup_tables', "Lookup table keys must be strings, got key of type '" . \gettype($key) . "'");
            }

            if (!\is_string($tableId) || $tableId === '') {
                throw new InvalidConfigurationException("mixpanel.lookup_tables.{$key}", "Lookup table '{$key}' must be a non-empty string");
            }
        }

        /** @var non-empty-array<string, non-empty-string> $value */
        return $value;
    }
}
