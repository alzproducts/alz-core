<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\GoogleAds;

use App\Application\Contracts\GoogleAdsClientInterface;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use RuntimeException;

final class GoogleAdsClientFactory
{
    public static function create(): GoogleAdsClientInterface
    {
        $clientId = \config('google-ads.client_id');
        $clientSecret = \config('google-ads.client_secret');
        $refreshToken = \config('google-ads.refresh_token');
        $developerToken = \config('google-ads.developer_token');
        $customerId = \config('google-ads.customer_id');

        if (!\is_string($clientId) || ($clientId === '')) {
            throw new RuntimeException('GOOGLE_ADS_CLIENT_ID not configured');
        }
        if (!\is_string($clientSecret) || ($clientSecret === '')) {
            throw new RuntimeException('GOOGLE_ADS_CLIENT_SECRET not configured');
        }
        if (!\is_string($refreshToken) || ($refreshToken === '')) {
            throw new RuntimeException('GOOGLE_ADS_REFRESH_TOKEN not configured');
        }
        if (!\is_string($developerToken) || ($developerToken === '')) {
            throw new RuntimeException('GOOGLE_ADS_DEVELOPER_TOKEN not configured');
        }
        if (!\is_string($customerId) || ($customerId === '')) {
            throw new RuntimeException('GOOGLE_ADS_CUSTOMER_ID not configured');
        }

        $oauth = new OAuth2TokenBuilder()
            ->withClientId($clientId)
            ->withClientSecret($clientSecret)
            ->withRefreshToken($refreshToken)
            ->build();

        $sdkClient = new GoogleAdsClientBuilder()
            ->withOAuth2Credential($oauth)
            ->withDeveloperToken($developerToken)
            ->build();

        return new GoogleAdsClient($sdkClient, $customerId);
    }
}
