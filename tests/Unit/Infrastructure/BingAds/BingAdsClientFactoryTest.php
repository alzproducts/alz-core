<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\BingAds\BingAdsClientFactory;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BingAdsClientFactory Unit Tests.
 *
 * Tests factory creation with config validation.
 * Uses Laravel config facade mocking to simulate various configuration states.
 *
 * Note: Does not use CoversClass because factory has hard dependencies on
 * Microsoft SDK classes that make coverage validation complex.
 */
final class BingAdsClientFactoryTest extends TestCase
{
    private BingAdsSessionManager&MockInterface $sessionManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionManager = Mockery::mock(BingAdsSessionManager::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Config Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_client_id_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_CLIENT_ID' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_client_secret_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_CLIENT_SECRET' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_refresh_token_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_REFRESH_TOKEN' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_developer_token_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_DEVELOPER_TOKEN' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_account_id_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', 'valid-dev-token');
        Config::set('bing-ads.account_id', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_ACCOUNT_ID' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_customer_id_is_not_configured(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', 'valid-dev-token');
        Config::set('bing-ads.account_id', 'valid-account');
        Config::set('bing-ads.customer_id', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Required configuration 'BING_ADS_CUSTOMER_ID' is missing or invalid");

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_environment_is_not_string(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', 'valid-dev-token');
        Config::set('bing-ads.account_id', 'valid-account');
        Config::set('bing-ads.customer_id', 'valid-customer');
        Config::set('bing-ads.environment', 123); // Not a string

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('BING_ADS_ENVIRONMENT must be a string');

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_poll_interval_is_not_integer(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', 'valid-dev-token');
        Config::set('bing-ads.account_id', 'valid-account');
        Config::set('bing-ads.customer_id', 'valid-customer');
        Config::set('bing-ads.environment', 'Production');
        Config::set('bing-ads.report_poll_interval_seconds', '10'); // String, not int

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('BING_ADS_REPORT_POLL_INTERVAL must be an integer');

        BingAdsClientFactory::create($this->sessionManager);
    }

    #[Test]
    public function it_throws_when_poll_max_attempts_is_not_integer(): void
    {
        Config::set('bing-ads.client_id', 'valid-id');
        Config::set('bing-ads.client_secret', 'valid-secret');
        Config::set('bing-ads.refresh_token', 'valid-token');
        Config::set('bing-ads.developer_token', 'valid-dev-token');
        Config::set('bing-ads.account_id', 'valid-account');
        Config::set('bing-ads.customer_id', 'valid-customer');
        Config::set('bing-ads.environment', 'Production');
        Config::set('bing-ads.report_poll_interval_seconds', 10);
        Config::set('bing-ads.report_poll_max_attempts', '30'); // String, not int

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('BING_ADS_REPORT_POLL_MAX_ATTEMPTS must be an integer');

        BingAdsClientFactory::create($this->sessionManager);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency Validation Tests (Integration-style)
    |--------------------------------------------------------------------------
    |
    | Note: Full factory creation tests are better suited as integration tests
    | since they require actual SOAP calls. The config validation tests above
    | cover the factory's responsibility for validating configuration.
    |
    | The currency validation logic is tested via error message verification
    | in real scenarios. See smoke tests for full pipeline validation.
    |--------------------------------------------------------------------------
    */
}
