<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ReviewsIo;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\ReviewsIo\ReviewsIoConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ReviewsIoConfig Unit Tests.
 *
 * Tests the immutable configuration value object for Reviews.io API client.
 * Covers fail-fast validation of credentials and boundary conditions for
 * timeout, retry times, and retry delay parameters.
 */
#[CoversClass(ReviewsIoConfig::class)]
final class ReviewsIoConfigTest extends TestCase
{
    private const string TEST_API_KEY = 'test-api-key';
    private const string TEST_STORE_ID = 'test-store-id';

    /*
    |--------------------------------------------------------------------------
    | Credential Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_valid_credentials(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
        );

        $this->assertSame(self::TEST_API_KEY, $config->apiKey);
        $this->assertSame(self::TEST_STORE_ID, $config->storeId);
        $this->assertSame('https://api.reviews.co.uk/', $config->baseUrl);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(3, $config->retryTimes);
        $this->assertSame(100, $config->retryDelay);
    }

    #[Test]
    public function it_throws_exception_for_empty_api_key(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Reviews.io API key cannot be empty');

        new ReviewsIoConfig(
            apiKey: '',
            storeId: self::TEST_STORE_ID,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_store_id(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Reviews.io store ID cannot be empty');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Timeout Boundary Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_timeout_at_minimum_boundary_of_one_second(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            timeout: 1,
        );

        $this->assertSame(1, $config->timeout);
    }

    #[Test]
    public function it_accepts_timeout_at_maximum_boundary_of_300_seconds(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            timeout: 300,
        );

        $this->assertSame(300, $config->timeout);
    }

    #[Test]
    public function it_throws_exception_for_timeout_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-300 seconds, got 0');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            timeout: 0,
        );
    }

    #[Test]
    public function it_throws_exception_for_timeout_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-300 seconds, got 301');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
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
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryTimes: 0,
        );

        $this->assertSame(0, $config->retryTimes);
    }

    #[Test]
    public function it_accepts_retry_times_at_maximum_boundary_of_10(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryTimes: 10,
        );

        $this->assertSame(10, $config->retryTimes);
    }

    #[Test]
    public function it_throws_exception_for_retry_times_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry times must be between 0-10, got -1');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryTimes: -1,
        );
    }

    #[Test]
    public function it_throws_exception_for_retry_times_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry times must be between 0-10, got 11');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
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
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryDelay: 0,
        );

        $this->assertSame(0, $config->retryDelay);
    }

    #[Test]
    public function it_accepts_retry_delay_at_maximum_boundary_of_5000_milliseconds(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryDelay: 5000,
        );

        $this->assertSame(5000, $config->retryDelay);
    }

    #[Test]
    public function it_throws_exception_for_retry_delay_below_minimum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must be between 0-5000ms, got -1');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryDelay: -1,
        );
    }

    #[Test]
    public function it_throws_exception_for_retry_delay_above_maximum_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must be between 0-5000ms, got 5001');

        new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            retryDelay: 5001,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Constants Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_max_batch_size_constant(): void
    {
        $this->assertSame(100, ReviewsIoConfig::MAX_BATCH_SIZE);
    }

    #[Test]
    public function it_exposes_sku_delimiter_constant(): void
    {
        $this->assertSame(';', ReviewsIoConfig::SKU_DELIMITER);
    }

    #[Test]
    public function it_allows_custom_base_url(): void
    {
        $config = new ReviewsIoConfig(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            baseUrl: 'https://custom.api.com/',
        );

        $this->assertSame('https://custom.api.com/', $config->baseUrl);
    }
}
