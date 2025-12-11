<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsClientInterface;
use Illuminate\Cache\CacheManager;
use Microsoft\BingAds\V13\CustomerManagement\CurrencyCode;
use RuntimeException;

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
    public static function create(CacheManager $cache): BingAdsClientInterface
    {
        $config = self::createConfig();
        $sessionManager = new BingAdsSessionManager($config, $cache);
        $transport = new BingAdsTransport($sessionManager, $config);

        self::validateCurrency($transport);

        return new BingAdsClient($transport);
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
            throw new RuntimeException('BING_ADS_CLIENT_ID not configured');
        }
        if (!\is_string($clientSecret)) {
            throw new RuntimeException('BING_ADS_CLIENT_SECRET not configured');
        }
        if (!\is_string($refreshToken)) {
            throw new RuntimeException('BING_ADS_REFRESH_TOKEN not configured');
        }
        if (!\is_string($developerToken)) {
            throw new RuntimeException('BING_ADS_DEVELOPER_TOKEN not configured');
        }
        if (!\is_string($accountId)) {
            throw new RuntimeException('BING_ADS_ACCOUNT_ID not configured');
        }
        if (!\is_string($customerId)) {
            throw new RuntimeException('BING_ADS_CUSTOMER_ID not configured');
        }
        if (!\is_string($environment)) {
            throw new RuntimeException('BING_ADS_ENVIRONMENT must be a string');
        }
        if (!\is_int($pollInterval)) {
            throw new RuntimeException('BING_ADS_REPORT_POLL_INTERVAL must be an integer');
        }
        if (!\is_int($pollMaxAttempts)) {
            throw new RuntimeException('BING_ADS_REPORT_POLL_MAX_ATTEMPTS must be an integer');
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
     * @throws RuntimeException When account currency is not GBP
     */
    private static function validateCurrency(BingAdsTransport $transport): void
    {
        $account = $transport->getAccount();

        // Account is stdClass with CurrencyCode as string (e.g., 'GBP')
        if ($account->CurrencyCode !== CurrencyCode::GBP) {
            throw new RuntimeException(
                \sprintf(
                    'Bing Ads account currency must be GBP, got %s. '
                    . 'Our domain model assumes costs are in British pounds.',
                    $account->CurrencyCode,
                ),
            );
        }
    }
}
