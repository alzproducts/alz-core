<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mixpanel;

use App\Infrastructure\Mixpanel\MixpanelConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MixpanelConfig Unit Tests.
 *
 * Tests the immutable configuration value object for Mixpanel API client.
 * Covers fail-fast validation of required credentials (non-empty strings)
 * and boundary conditions for timeout, retry times, and retry delay parameters.
 */
#[CoversClass(MixpanelConfig::class)]
final class MixpanelConfigTest extends TestCase
{
    private const string TEST_DATA_API_BASE_URL = 'https://custom.mixpanel.com/api';
    private const string TEST_SERVICE_ACCOUNT_USERNAME = 'test-username';
    private const string TEST_SERVICE_ACCOUNT_PASSWORD = 'test-password';
    private const string TEST_PROJECT_ID = 'test-project-id-123';
    /** @var array<string, string> */
    private const array TEST_LOOKUP_TABLE_IDS = ['utm_campaigns' => 'test-lookup-table-id-456'];

    /*
    |--------------------------------------------------------------------------
    | Valid Construction and Default Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_all_valid_required_parameters_and_default_optional_values(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: MixpanelConfig::DEFAULT_DATA_API_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
        );

        $this->assertSame(MixpanelConfig::DEFAULT_DATA_API_URL, $config->dataApiBaseUrl);
        $this->assertSame(self::TEST_SERVICE_ACCOUNT_USERNAME, $config->serviceAccountUsername);
        $this->assertSame(self::TEST_SERVICE_ACCOUNT_PASSWORD, $config->serviceAccountPassword);
        $this->assertSame(self::TEST_PROJECT_ID, $config->projectId);
        $this->assertSame(self::TEST_LOOKUP_TABLE_IDS, $config->lookupTableIds);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(3, $config->retryTimes);
        $this->assertSame(100, $config->retryDelay);
    }

    #[Test]
    public function it_creates_config_with_all_valid_parameters_including_custom_optional_values(): void
    {
        $customTimeout = 60;
        $customRetryTimes = 5;
        $customRetryDelay = 500;

        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: $customTimeout,
            retryTimes: $customRetryTimes,
            retryDelay: $customRetryDelay,
        );

        $this->assertSame(self::TEST_DATA_API_BASE_URL, $config->dataApiBaseUrl);
        $this->assertSame(self::TEST_SERVICE_ACCOUNT_USERNAME, $config->serviceAccountUsername);
        $this->assertSame(self::TEST_SERVICE_ACCOUNT_PASSWORD, $config->serviceAccountPassword);
        $this->assertSame(self::TEST_PROJECT_ID, $config->projectId);
        $this->assertSame(self::TEST_LOOKUP_TABLE_IDS, $config->lookupTableIds);
        $this->assertSame($customTimeout, $config->timeout);
        $this->assertSame($customRetryTimes, $config->retryTimes);
        $this->assertSame($customRetryDelay, $config->retryDelay);
    }

    /*
    |--------------------------------------------------------------------------
    | Required String Parameter Validation Tests (Empty String)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_for_empty_data_api_base_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixpanel data API base URL cannot be empty');

        new MixpanelConfig(
            dataApiBaseUrl: '',
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_service_account_username(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixpanel service account username cannot be empty');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: '',
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_service_account_password(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixpanel service account password cannot be empty');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: '',
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_project_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mixpanel project ID cannot be empty');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: '',
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
        );
    }

    // Note: Lookup table array validation tests are in MixpanelClientFactoryTest
    // Factory validates type + non-empty before passing to Config

    /*
    |--------------------------------------------------------------------------
    | Timeout Boundary Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_timeout_at_minimum_boundary_of_one_second(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: 1,
        );

        $this->assertSame(1, $config->timeout);
    }

    #[Test]
    public function it_accepts_timeout_at_maximum_boundary_of_300_seconds(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: 300,
        );

        $this->assertSame(300, $config->timeout);
    }

    #[Test]
    public function it_throws_exception_for_timeout_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-300 seconds, got 0');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: 0,
        );
    }

    #[Test]
    public function it_throws_exception_for_timeout_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-300 seconds, got 301');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: 301,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Times Boundary Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_retry_times_at_minimum_boundary_of_zero(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryTimes: 0,
        );

        $this->assertSame(0, $config->retryTimes);
    }

    #[Test]
    public function it_accepts_retry_times_at_maximum_boundary_of_10(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryTimes: 10,
        );

        $this->assertSame(10, $config->retryTimes);
    }

    #[Test]
    public function it_throws_exception_for_retry_times_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry times must be between 0-10, got -1');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryTimes: -1,
        );
    }

    #[Test]
    public function it_throws_exception_for_retry_times_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry times must be between 0-10, got 11');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryTimes: 11,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Delay Boundary Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_retry_delay_at_minimum_boundary_of_zero_milliseconds(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryDelay: 0,
        );

        $this->assertSame(0, $config->retryDelay);
    }

    #[Test]
    public function it_accepts_retry_delay_at_maximum_boundary_of_5000_milliseconds(): void
    {
        $config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryDelay: 5000,
        );

        $this->assertSame(5000, $config->retryDelay);
    }

    #[Test]
    public function it_throws_exception_for_retry_delay_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must be between 0-5000ms, got -1');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryDelay: -1,
        );
    }

    #[Test]
    public function it_throws_exception_for_retry_delay_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must be between 0-5000ms, got 5001');

        new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            serviceAccountUsername: self::TEST_SERVICE_ACCOUNT_USERNAME,
            serviceAccountPassword: self::TEST_SERVICE_ACCOUNT_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            retryDelay: 5001,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Constants Accessibility Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_main_api_url_constant(): void
    {
        $this->assertSame('https://mixpanel.com', MixpanelConfig::MAIN_API_URL);
    }

    #[Test]
    public function it_exposes_default_data_api_url_constant(): void
    {
        $this->assertSame('https://api-eu.mixpanel.com', MixpanelConfig::DEFAULT_DATA_API_URL);
    }
}
