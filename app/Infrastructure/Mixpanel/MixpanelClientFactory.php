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
     */
    private static function createConfig(): MixpanelConfig
    {
        $baseUrl = \config('mixpanel.base_url');
        $projectId = \config('mixpanel.project_id');
        $serviceAccountUsername = \config('mixpanel.service_account_username');
        $serviceAccountPassword = \config('mixpanel.service_account_password');
        $lookupTableIds = \config('mixpanel.lookup_tables');
        $timeout = \config('mixpanel.timeout');
        $retryTimes = \config('mixpanel.retry_times');
        $retryDelay = \config('mixpanel.retry_delay');

        if (!\is_string($baseUrl)) {
            throw new InvalidConfigurationException('MIXPANEL_BASE_URL');
        }
        if (!\is_string($projectId)) {
            throw new InvalidConfigurationException('MIXPANEL_PROJECT_ID');
        }
        if (!\is_string($serviceAccountUsername)) {
            throw new InvalidConfigurationException('MIXPANEL_SERVICE_ACCOUNT_USERNAME');
        }
        if (!\is_string($serviceAccountPassword)) {
            throw new InvalidConfigurationException('MIXPANEL_SERVICE_ACCOUNT_PASSWORD');
        }
        if (!\is_array($lookupTableIds) || $lookupTableIds === []) {
            throw new InvalidConfigurationException('mixpanel.lookup_tables', 'mixpanel.lookup_tables must be a non-empty array');
        }
        foreach ($lookupTableIds as $key => $tableId) {
            if (!\is_string($tableId) || $tableId === '') {
                throw new InvalidConfigurationException("mixpanel.lookup_tables.{$key}", "Lookup table '{$key}' must be a non-empty string");
            }
        }
        /** @var array<string, string> $lookupTableIds */
        if (!\is_int($timeout)) {
            throw new InvalidConfigurationException('MIXPANEL_TIMEOUT', 'MIXPANEL_TIMEOUT must be an integer');
        }
        if (!\is_int($retryTimes)) {
            throw new InvalidConfigurationException('MIXPANEL_RETRY_TIMES', 'MIXPANEL_RETRY_TIMES must be an integer');
        }
        if (!\is_int($retryDelay)) {
            throw new InvalidConfigurationException('MIXPANEL_RETRY_DELAY', 'MIXPANEL_RETRY_DELAY must be an integer');
        }

        return new MixpanelConfig(
            dataApiBaseUrl: $baseUrl,
            serviceAccountUsername: $serviceAccountUsername,
            serviceAccountPassword: $serviceAccountPassword,
            projectId: $projectId,
            lookupTableIds: $lookupTableIds,
            timeout: $timeout,
            retryTimes: $retryTimes,
            retryDelay: $retryDelay,
        );
    }
}
