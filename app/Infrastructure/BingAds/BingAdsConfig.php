<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\InvalidConfigurationException;
use InvalidArgumentException;

/**
 * Immutable configuration for Bing Ads (Microsoft Advertising) API client.
 *
 * This value object encapsulates all configuration needed to communicate
 * with the Bing Ads API. Validation happens at construction time (fail-fast),
 * ensuring the client always receives valid configuration.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class BingAdsConfig
{
    /**
     * Microsoft OAuth2 token endpoint for all tenants.
     */
    public const string TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    /**
     * Maximum allowed poll interval in seconds.
     */
    private const int MAX_POLL_INTERVAL_SECONDS = 120;

    /**
     * Maximum allowed poll attempts.
     */
    private const int MAX_POLL_ATTEMPTS = 100;

    /**
     * @param string $clientId Azure AD OAuth2 client ID
     * @param string $clientSecret Azure AD OAuth2 client secret
     * @param string $refreshToken OAuth2 refresh token for authentication
     * @param string $developerToken Bing Ads API developer token
     * @param string $accountId Bing Ads account ID
     * @param string $customerId Bing Ads customer ID
     * @param string $environment API environment: 'Production' or 'Sandbox'
     * @param int $reportPollIntervalSeconds Seconds between report status polls (1-120)
     * @param int $reportPollMaxAttempts Maximum report poll attempts before timeout (1-100)
     *
     * @throws InvalidArgumentException When parameters are invalid
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $developerToken,
        public string $accountId,
        public string $customerId,
        public string $environment = 'Production',
        public int $reportPollIntervalSeconds = 10,
        public int $reportPollMaxAttempts = 30,
    ) {
        if ($clientId === '') {
            throw new InvalidConfigurationException('bing-ads.client_id', 'Bing Ads client ID cannot be empty');
        }

        if ($clientSecret === '') {
            throw new InvalidConfigurationException('bing-ads.client_secret', 'Bing Ads client secret cannot be empty');
        }

        if ($refreshToken === '') {
            throw new InvalidConfigurationException('bing-ads.refresh_token', 'Bing Ads refresh token cannot be empty');
        }

        if ($developerToken === '') {
            throw new InvalidConfigurationException('bing-ads.developer_token', 'Bing Ads developer token cannot be empty');
        }

        if ($accountId === '') {
            throw new InvalidConfigurationException('bing-ads.account_id', 'Bing Ads account ID cannot be empty');
        }

        if ($customerId === '') {
            throw new InvalidConfigurationException('bing-ads.customer_id', 'Bing Ads customer ID cannot be empty');
        }

        if (!\in_array($environment, ['Production', 'Sandbox'], true)) {
            throw new InvalidArgumentException(
                \sprintf("Bing Ads environment must be 'Production' or 'Sandbox', got '%s'", $environment),
            );
        }

        if (($reportPollIntervalSeconds < 1) || ($reportPollIntervalSeconds > self::MAX_POLL_INTERVAL_SECONDS)) {
            throw new InvalidArgumentException(
                \sprintf('Report poll interval must be between 1-%d seconds, got %d', self::MAX_POLL_INTERVAL_SECONDS, $reportPollIntervalSeconds),
            );
        }

        if (($reportPollMaxAttempts < 1) || ($reportPollMaxAttempts > self::MAX_POLL_ATTEMPTS)) {
            throw new InvalidArgumentException(
                \sprintf('Report poll max attempts must be between 1-%d, got %d', self::MAX_POLL_ATTEMPTS, $reportPollMaxAttempts),
            );
        }
    }
}
