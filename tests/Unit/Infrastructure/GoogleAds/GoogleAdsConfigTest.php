<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\GoogleAds\GoogleAdsConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(GoogleAdsConfig::class)]
final class GoogleAdsConfigTest extends TestCase
{
    #[Test]
    public function it_creates_config_with_valid_credentials(): void
    {
        $config = new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
        );

        $this->assertSame('test-client-id', $config->clientId);
        $this->assertSame('test-client-secret', $config->clientSecret);
        $this->assertSame('test-refresh-token', $config->refreshToken);
        $this->assertSame('test-developer-token', $config->developerToken);
        $this->assertSame('1234567890', $config->customerId);
        $this->assertNull($config->loginCustomerId);
    }

    #[Test]
    public function it_creates_config_with_login_customer_id(): void
    {
        $config = new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
            loginCustomerId: '0987654321',
        );

        $this->assertSame('0987654321', $config->loginCustomerId);
    }

    #[Test]
    public function it_throws_when_client_id_is_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads client ID cannot be empty');

        new GoogleAdsConfig(
            clientId: '',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
        );
    }

    #[Test]
    public function it_throws_when_client_secret_is_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads client secret cannot be empty');

        new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: '',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
        );
    }

    #[Test]
    public function it_throws_when_refresh_token_is_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads refresh token cannot be empty');

        new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: '',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
        );
    }

    #[Test]
    public function it_throws_when_developer_token_is_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads developer token cannot be empty');

        new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: '',
            customerId: '1234567890',
        );
    }

    #[Test]
    public function it_throws_when_customer_id_is_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads customer ID cannot be empty');

        new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '',
        );
    }

    #[Test]
    public function it_throws_when_login_customer_id_is_empty_string(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Google Ads login customer ID cannot be empty when provided');

        new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
            loginCustomerId: '',
        );
    }
}
