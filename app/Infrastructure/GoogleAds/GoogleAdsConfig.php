<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use RuntimeException;

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
     *
     * @throws RuntimeException When required credentials are empty
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
            throw new RuntimeException('Google Ads client ID cannot be empty');
        }

        if ($clientSecret === '') {
            throw new RuntimeException('Google Ads client secret cannot be empty');
        }

        if ($refreshToken === '') {
            throw new RuntimeException('Google Ads refresh token cannot be empty');
        }

        if ($developerToken === '') {
            throw new RuntimeException('Google Ads developer token cannot be empty');
        }

        if ($customerId === '') {
            throw new RuntimeException('Google Ads customer ID cannot be empty');
        }

        if ($loginCustomerId === '') {
            throw new RuntimeException('Google Ads login customer ID cannot be empty when provided');
        }
    }
}
