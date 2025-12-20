<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Immutable configuration for Google Ads API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Google Ads API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * Note: The Google Ads SDK handles timeout, retry, and connection pooling
 * internally. This config only contains authentication credentials.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class GoogleAdsConfig
{
    /**
     * @param string $clientId OAuth2 client ID
     * @param string $clientSecret OAuth2 client secret
     * @param string $refreshToken OAuth2 refresh token for authentication
     * @param string $developerToken Google Ads API developer token
     * @param string $customerId Google Ads customer ID (without hyphens)
     * @param string|null $loginCustomerId Manager account ID for delegated access (MCC)
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $developerToken,
        public string $customerId,
        public ?string $loginCustomerId = null,
    ) {
        if ($clientId === '') {
            throw new InvalidConfigurationException('google-ads.client_id', 'Google Ads client ID cannot be empty');
        }

        if ($clientSecret === '') {
            throw new InvalidConfigurationException('google-ads.client_secret', 'Google Ads client secret cannot be empty');
        }

        if ($refreshToken === '') {
            throw new InvalidConfigurationException('google-ads.refresh_token', 'Google Ads refresh token cannot be empty');
        }

        if ($developerToken === '') {
            throw new InvalidConfigurationException('google-ads.developer_token', 'Google Ads developer token cannot be empty');
        }

        if ($customerId === '') {
            throw new InvalidConfigurationException('google-ads.customer_id', 'Google Ads customer ID cannot be empty');
        }

        if ($loginCustomerId === '') {
            throw new InvalidConfigurationException('google-ads.login_customer_id', 'Google Ads login customer ID cannot be empty when provided');
        }
    }
}
