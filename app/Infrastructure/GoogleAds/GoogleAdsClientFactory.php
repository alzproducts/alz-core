<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Support\TransientLogThrottle;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;

/**
 * Factory for creating GoogleAdsClient with all dependencies.
 *
 * Follows the template pattern: Config → SDK → Transport → Client.
 * Validates configuration at boot time (fail-fast).
 */
final class GoogleAdsClientFactory
{
    public static function create(TransientLogThrottle $logThrottle): GoogleAdsClientInterface
    {
        $config = self::createConfig();
        $sdkClient = self::buildSdkClient($config);
        $transport = new GoogleAdsTransport($sdkClient, $config, $logThrottle);

        return new GoogleAdsClient($transport);
    }

    public static function createConversionClient(TransientLogThrottle $logThrottle): GoogleAdsConversionClient
    {
        $config = self::createConversionConfig();
        $sdkClient = self::buildSdkClient($config);
        $transport = new GoogleAdsTransport($sdkClient, $config, $logThrottle);

        return new GoogleAdsConversionClient($transport, $config);
    }

    /**
     * Create config from Laravel configuration.
     *
     * Validates that environment variables are set before constructing.
     * GoogleAdsConfig handles domain validation (non-empty strings).
     */
    private static function createConfig(): GoogleAdsConfig
    {
        $clientId = \config('google-ads.client_id');
        $clientSecret = \config('google-ads.client_secret');
        $refreshToken = \config('google-ads.refresh_token');
        $developerToken = \config('google-ads.developer_token');
        $customerId = \config('google-ads.customer_id');
        $loginCustomerId = \config('google-ads.login_customer_id');

        if (!\is_string($clientId)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_CLIENT_ID');
        }
        if (!\is_string($clientSecret)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_CLIENT_SECRET');
        }
        if (!\is_string($refreshToken)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_REFRESH_TOKEN');
        }
        if (!\is_string($developerToken)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_DEVELOPER_TOKEN');
        }
        if (!\is_string($customerId)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_CUSTOMER_ID');
        }
        if (($loginCustomerId !== null) && !\is_string($loginCustomerId)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_LOGIN_CUSTOMER_ID', 'GOOGLE_ADS_LOGIN_CUSTOMER_ID must be a string when provided');
        }

        return new GoogleAdsConfig(
            clientId: $clientId,
            clientSecret: $clientSecret,
            refreshToken: $refreshToken,
            developerToken: $developerToken,
            customerId: $customerId,
            loginCustomerId: $loginCustomerId,
        );
    }

    /**
     * Create conversion-capable config by extending the base config with conversion action IDs.
     */
    public static function createConversionConfig(): GoogleAdsConfig
    {
        $leadId = \config('google-ads.lead_conversion_action_id');
        $quoteId = \config('google-ads.quote_conversion_action_id');
        if (!\is_string($leadId)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_LEAD_CONVERSION_ID');
        }
        if (!\is_string($quoteId)) {
            throw new InvalidConfigurationException('GOOGLE_ADS_QUOTE_CONVERSION_ID');
        }

        return self::createConfig()->withConversionActionIds($leadId, $quoteId);
    }

    /**
     * Build the Google Ads SDK client with OAuth2 credentials.
     */
    private static function buildSdkClient(GoogleAdsConfig $config): SdkGoogleAdsClient
    {
        $oauth = new OAuth2TokenBuilder()
            ->withClientId($config->clientId)
            ->withClientSecret($config->clientSecret)
            ->withRefreshToken($config->refreshToken)
            ->build();

        $builder = new GoogleAdsClientBuilder()
            ->withOAuth2Credential($oauth)
            ->withDeveloperToken($config->developerToken);

        if ($config->loginCustomerId !== null) {
            $builder = $builder->withLoginCustomerId((int) $config->loginCustomerId);
        }

        return $builder->build();
    }
}
