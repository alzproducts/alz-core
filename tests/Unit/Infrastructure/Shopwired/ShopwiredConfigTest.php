<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\ShopwiredConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * ShopwiredConfig Unit Tests.
 *
 * Tests the immutable configuration value object for Shopwired API client.
 * Covers fail-fast validation of credentials and boundary conditions for
 * timeout, retry times, and retry delay parameters.
 */
#[CoversClass(ShopwiredConfig::class)]
final class ShopwiredConfigTest extends TestCase
{
    private const string TEST_API_KEY = 'test-api-key';
    private const string TEST_API_SECRET = 'test-api-secret';

    /*
    |--------------------------------------------------------------------------
    | Credential Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_valid_credentials(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
        );

        $this->assertSame(self::TEST_API_KEY, $config->apiKey);
        $this->assertSame(self::TEST_API_SECRET, $config->apiSecret);
        $this->assertSame('https://api.ecommerceapi.uk/v1', $config->baseUrl);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(3, $config->retryTimes);
        $this->assertSame(100, $config->retryDelay);
    }

    #[Test]
    public function it_creates_config_with_all_custom_values(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            baseUrl: 'https://custom.api.com/v2',
            timeout: 60,
            retryTimes: 5,
            retryDelay: 200,
        );

        $this->assertSame(self::TEST_API_KEY, $config->apiKey);
        $this->assertSame(self::TEST_API_SECRET, $config->apiSecret);
        $this->assertSame('https://custom.api.com/v2', $config->baseUrl);
        $this->assertSame(60, $config->timeout);
        $this->assertSame(5, $config->retryTimes);
        $this->assertSame(200, $config->retryDelay);
    }

    #[Test]
    public function it_throws_exception_for_empty_api_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopwired API key cannot be empty');

        new ShopwiredConfig(
            apiKey: '',
            apiSecret: self::TEST_API_SECRET,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_api_secret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopwired API secret cannot be empty');

        new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Numeric Parameter Boundary Tests (DataProvider)
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{timeout?: int, retryTimes?: int, retryDelay?: int} $invalidConfig
     * @param class-string<Throwable> $expectedException
     */
    #[Test]
    #[DataProvider('invalidNumericConfigs')]
    public function it_throws_exception_for_out_of_bounds_numeric_parameters(
        array $invalidConfig,
        string $expectedException,
        string $expectedMessage,
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $config = [
            'apiKey' => self::TEST_API_KEY,
            'apiSecret' => self::TEST_API_SECRET,
            'timeout' => 30,
            'retryTimes' => 3,
            'retryDelay' => 100,
            ...$invalidConfig,
        ];

        new ShopwiredConfig(...$config);
    }

    /**
     * @return array<string, array{array<string, int>, class-string<Throwable>, string}>
     */
    public static function invalidNumericConfigs(): array
    {
        return [
            'timeout too low' => [['timeout' => 0], InvalidArgumentException::class, 'Timeout must be between 1-300 seconds, got 0'],
            'timeout too high' => [['timeout' => 301], InvalidArgumentException::class, 'Timeout must be between 1-300 seconds, got 301'],
            'retry times too low' => [['retryTimes' => -1], InvalidArgumentException::class, 'Retry times must be between 0-10, got -1'],
            'retry times too high' => [['retryTimes' => 11], InvalidArgumentException::class, 'Retry times must be between 0-10, got 11'],
            'retry delay too low' => [['retryDelay' => -1], InvalidArgumentException::class, 'Retry delay must be between 0-5000ms, got -1'],
            'retry delay too high' => [['retryDelay' => 5001], InvalidArgumentException::class, 'Retry delay must be between 0-5000ms, got 5001'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary Success Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_timeout_at_minimum_boundary_of_one_second(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            timeout: 1,
        );

        $this->assertSame(1, $config->timeout);
    }

    #[Test]
    public function it_accepts_timeout_at_maximum_boundary_of_300_seconds(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            timeout: 300,
        );

        $this->assertSame(300, $config->timeout);
    }

    #[Test]
    public function it_accepts_retry_times_at_minimum_boundary_of_zero(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            retryTimes: 0,
        );

        $this->assertSame(0, $config->retryTimes);
    }

    #[Test]
    public function it_accepts_retry_times_at_maximum_boundary_of_10(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            retryTimes: 10,
        );

        $this->assertSame(10, $config->retryTimes);
    }

    #[Test]
    public function it_accepts_retry_delay_at_minimum_boundary_of_zero_milliseconds(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            retryDelay: 0,
        );

        $this->assertSame(0, $config->retryDelay);
    }

    #[Test]
    public function it_accepts_retry_delay_at_maximum_boundary_of_5000_milliseconds(): void
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            retryDelay: 5000,
        );

        $this->assertSame(5000, $config->retryDelay);
    }

    /*
    |--------------------------------------------------------------------------
    | Constants Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_default_base_url_constant(): void
    {
        $this->assertSame('https://api.ecommerceapi.uk/v1', ShopwiredConfig::DEFAULT_BASE_URL);
    }
}
