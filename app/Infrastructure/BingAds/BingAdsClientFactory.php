<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Microsoft\BingAds\V13\CustomerManagement\CurrencyCode;

/**
 * Factory for creating BingAdsClient with all dependencies.
 *
 * Follows the template pattern: Config → SessionManager → Transport → Client.
 * Validates configuration and currency at boot time (fail-fast).
 *
 * Currency validation ensures the account uses GBP, matching our domain model
 * expectation that CampaignMetrics::$costInPounds represents British pounds.
 */
final class BingAdsClientFactory
{
    /**
     * @throws UnexpectedApiResultException When account currency is not GBP
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public static function create(BingAdsSessionManager $sessionManager): BingAdsClientInterface
    {
        $config = self::createConfig();
        $transport = new BingAdsTransport($sessionManager, $config);

        self::validateCurrency($transport);

        return new BingAdsClient($transport);
    }

    public static function createConversionClient(BingAdsSessionManager $sessionManager): BingAdsConversionClient
    {
        $config = self::createConversionConfig();
        $transport = new BingAdsConversionTransport($sessionManager, $config);

        return new BingAdsConversionClient($transport, $config);
    }

    public static function createConversionConfig(): BingAdsConfig
    {
        $goalName = \config('bing-ads.offline_lead_conversion_goal_name');
        if (!\is_string($goalName)) {
            throw new InvalidConfigurationException('BING_ADS_OFFLINE_LEAD_CONVERSION_GOAL_NAME');
        }

        return self::createConfig()->withOfflineLeadConversionGoalName($goalName);
    }

    /**
     * Create config from Laravel configuration.
     *
     * Validates that environment variables are set before constructing.
     * BingAdsConfig handles domain validation (non-empty strings, valid ranges).
     */
    private static function createConfig(): BingAdsConfig
    {
        $clientId = \config('bing-ads.client_id');
        $clientSecret = \config('bing-ads.client_secret');
        $refreshToken = \config('bing-ads.refresh_token');
        $developerToken = \config('bing-ads.developer_token');
        $accountId = \config('bing-ads.account_id');
        $customerId = \config('bing-ads.customer_id');
        $environment = \config('bing-ads.environment', 'Production');
        $pollInterval = \config('bing-ads.report_poll_interval_seconds', 10);
        $pollMaxAttempts = \config('bing-ads.report_poll_max_attempts', 30);

        if (!\is_string($clientId)) {
            throw new InvalidConfigurationException('BING_ADS_CLIENT_ID');
        }
        if (!\is_string($clientSecret)) {
            throw new InvalidConfigurationException('BING_ADS_CLIENT_SECRET');
        }
        if (!\is_string($refreshToken)) {
            throw new InvalidConfigurationException('BING_ADS_REFRESH_TOKEN');
        }
        if (!\is_string($developerToken)) {
            throw new InvalidConfigurationException('BING_ADS_DEVELOPER_TOKEN');
        }
        if (!\is_string($accountId)) {
            throw new InvalidConfigurationException('BING_ADS_ACCOUNT_ID');
        }
        if (!\is_string($customerId)) {
            throw new InvalidConfigurationException('BING_ADS_CUSTOMER_ID');
        }
        if (!\is_string($environment)) {
            throw new InvalidConfigurationException('BING_ADS_ENVIRONMENT', 'BING_ADS_ENVIRONMENT must be a string');
        }
        if (!\is_int($pollInterval)) {
            throw new InvalidConfigurationException('BING_ADS_REPORT_POLL_INTERVAL', 'BING_ADS_REPORT_POLL_INTERVAL must be an integer');
        }
        if (!\is_int($pollMaxAttempts)) {
            throw new InvalidConfigurationException('BING_ADS_REPORT_POLL_MAX_ATTEMPTS', 'BING_ADS_REPORT_POLL_MAX_ATTEMPTS must be an integer');
        }

        return new BingAdsConfig(
            clientId: $clientId,
            clientSecret: $clientSecret,
            refreshToken: $refreshToken,
            developerToken: $developerToken,
            accountId: $accountId,
            customerId: $customerId,
            environment: $environment,
            reportPollIntervalSeconds: $pollInterval,
            reportPollMaxAttempts: $pollMaxAttempts,
        );
    }

    /**
     * Validate that the Bing Ads account uses GBP currency.
     *
     * Our domain model assumes costs are in GBP. This fail-fast check
     * prevents runtime surprises from accounts using other currencies.
     *
     * Note: SDK PHPDoc says CurrencyCode is a class, but at runtime it's a string.
     *
     * @throws UnexpectedApiResultException When account currency is not GBP
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private static function validateCurrency(BingAdsTransport $transport): void
    {
        $account = $transport->getAccount();

        // Account is stdClass with CurrencyCode as string (e.g., 'GBP')
        if ($account->CurrencyCode !== CurrencyCode::GBP) {
            throw new UnexpectedApiResultException(
                'Bing Ads',
                \sprintf(
                    'Account currency must be GBP, got %s. Our domain model assumes costs are in British pounds.',
                    $account->CurrencyCode,
                ),
            );
        }
    }
}
