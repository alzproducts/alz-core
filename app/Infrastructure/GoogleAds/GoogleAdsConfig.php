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
     * @param string|null $leadConversionActionId Google Ads conversion action ID for Lead Received uploads (required for ConversionUploadService; null for read-only clients)
     * @param string|null $quoteConversionActionId Google Ads conversion action ID for Quote Issued uploads (required for ConversionUploadService; null for read-only clients)
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $developerToken,
        public string $customerId,
        public ?string $loginCustomerId = null,
        public ?string $leadConversionActionId = null,
        public ?string $quoteConversionActionId = null,
    ) {
        self::rejectEmpty($clientId, 'google-ads.client_id', 'Google Ads client ID cannot be empty');
        self::rejectEmpty($clientSecret, 'google-ads.client_secret', 'Google Ads client secret cannot be empty');
        self::rejectEmpty($refreshToken, 'google-ads.refresh_token', 'Google Ads refresh token cannot be empty');
        self::rejectEmpty($developerToken, 'google-ads.developer_token', 'Google Ads developer token cannot be empty');
        self::rejectEmpty($customerId, 'google-ads.customer_id', 'Google Ads customer ID cannot be empty');
        self::rejectEmpty($loginCustomerId, 'google-ads.login_customer_id', 'Google Ads login customer ID cannot be empty when provided');
        self::rejectEmpty($leadConversionActionId, 'google-ads.lead_conversion_action_id', 'Google Ads lead conversion action ID cannot be empty when provided');
        self::rejectEmpty($quoteConversionActionId, 'google-ads.quote_conversion_action_id', 'Google Ads quote conversion action ID cannot be empty when provided');
    }

    public function withConversionActionIds(string $leadActionId, string $quoteActionId): self
    {
        return new self(
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            refreshToken: $this->refreshToken,
            developerToken: $this->developerToken,
            customerId: $this->customerId,
            loginCustomerId: $this->loginCustomerId,
            leadConversionActionId: $leadActionId,
            quoteConversionActionId: $quoteActionId,
        );
    }

    private static function rejectEmpty(?string $value, string $configKey, string $message): void
    {
        if ($value === '') {
            throw new InvalidConfigurationException($configKey, $message);
        }
    }
}
