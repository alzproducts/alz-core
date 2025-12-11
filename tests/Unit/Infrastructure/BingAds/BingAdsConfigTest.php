<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Infrastructure\BingAds\BingAdsConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * BingAdsConfig Unit Tests.
 *
 * Tests the immutable configuration value object for Bing Ads API client.
 * Validates all credential requirements and parameter constraints.
 */
#[CoversClass(BingAdsConfig::class)]
final class BingAdsConfigTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Valid Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_valid_credentials(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client-123',
            clientSecret: 'secret-456',
            refreshToken: 'refresh-789',
            developerToken: 'dev-token',
            accountId: '12345678',
            customerId: '87654321',
        );

        $this->assertSame('client-123', $config->clientId);
        $this->assertSame('secret-456', $config->clientSecret);
        $this->assertSame('refresh-789', $config->refreshToken);
        $this->assertSame('dev-token', $config->developerToken);
        $this->assertSame('12345678', $config->accountId);
        $this->assertSame('87654321', $config->customerId);
        $this->assertSame('Production', $config->environment);
        $this->assertSame(10, $config->reportPollIntervalSeconds);
        $this->assertSame(30, $config->reportPollMaxAttempts);
    }

    #[Test]
    public function it_creates_config_with_sandbox_environment(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            environment: 'Sandbox',
        );

        $this->assertSame('Sandbox', $config->environment);
    }

    #[Test]
    public function it_creates_config_with_custom_poll_settings(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollIntervalSeconds: 5,
            reportPollMaxAttempts: 60,
        );

        $this->assertSame(5, $config->reportPollIntervalSeconds);
        $this->assertSame(60, $config->reportPollMaxAttempts);
    }

    #[Test]
    public function it_has_correct_token_url_constant(): void
    {
        $this->assertSame(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            BingAdsConfig::TOKEN_URL,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Credential Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_client_id_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads client ID cannot be empty');

        new BingAdsConfig(
            clientId: '',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
        );
    }

    #[Test]
    public function it_throws_when_client_secret_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads client secret cannot be empty');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: '',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
        );
    }

    #[Test]
    public function it_throws_when_refresh_token_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads refresh token cannot be empty');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: '',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
        );
    }

    #[Test]
    public function it_throws_when_developer_token_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads developer token cannot be empty');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: '',
            accountId: '123',
            customerId: '456',
        );
    }

    #[Test]
    public function it_throws_when_account_id_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads account ID cannot be empty');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '',
            customerId: '456',
        );
    }

    #[Test]
    public function it_throws_when_customer_id_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bing Ads customer ID cannot be empty');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Environment Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_environment_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Bing Ads environment must be 'Production' or 'Sandbox', got 'Development'");

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            environment: 'Development',
        );
    }

    #[Test]
    public function it_throws_when_environment_has_wrong_case(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Bing Ads environment must be 'Production' or 'Sandbox', got 'production'");

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            environment: 'production', // lowercase
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Poll Interval Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_minimum_poll_interval(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollIntervalSeconds: 1, // Minimum
        );

        $this->assertSame(1, $config->reportPollIntervalSeconds);
    }

    #[Test]
    public function it_accepts_maximum_poll_interval(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollIntervalSeconds: 120, // Maximum
        );

        $this->assertSame(120, $config->reportPollIntervalSeconds);
    }

    #[Test]
    public function it_throws_when_poll_interval_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report poll interval must be between 1-120 seconds, got 0');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollIntervalSeconds: 0,
        );
    }

    #[Test]
    public function it_throws_when_poll_interval_exceeds_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report poll interval must be between 1-120 seconds, got 121');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollIntervalSeconds: 121,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Poll Max Attempts Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_minimum_poll_attempts(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollMaxAttempts: 1, // Minimum
        );

        $this->assertSame(1, $config->reportPollMaxAttempts);
    }

    #[Test]
    public function it_accepts_maximum_poll_attempts(): void
    {
        $config = new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollMaxAttempts: 100, // Maximum
        );

        $this->assertSame(100, $config->reportPollMaxAttempts);
    }

    #[Test]
    public function it_throws_when_poll_attempts_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report poll max attempts must be between 1-100, got 0');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollMaxAttempts: 0,
        );
    }

    #[Test]
    public function it_throws_when_poll_attempts_exceeds_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report poll max attempts must be between 1-100, got 101');

        new BingAdsConfig(
            clientId: 'client',
            clientSecret: 'secret',
            refreshToken: 'token',
            developerToken: 'dev',
            accountId: '123',
            customerId: '456',
            reportPollMaxAttempts: 101,
        );
    }
}
