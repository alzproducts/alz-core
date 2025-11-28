<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Infrastructure\Linnworks\LinnworksConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * LinnworksConfig Unit Tests.
 *
 * Tests the immutable configuration value object for Linnworks API client.
 * Covers fail-fast validation of credentials and boundary conditions for
 * timeout and cache TTL buffer.
 */
#[CoversClass(LinnworksConfig::class)]
final class LinnworksConfigTest extends TestCase
{
    private const string TEST_APP_ID = 'test-application-id';
    private const string TEST_APP_SECRET = 'test-application-secret';
    private const string TEST_INSTALL_TOKEN = 'test-installation-token';

    /*
    |--------------------------------------------------------------------------
    | Credential Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_valid_credentials(): void
    {
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
        );

        $this->assertSame(self::TEST_APP_ID, $config->applicationId);
        $this->assertSame(self::TEST_APP_SECRET, $config->applicationSecret);
        $this->assertSame(self::TEST_INSTALL_TOKEN, $config->installationToken);
        $this->assertSame(30, $config->timeout);
        $this->assertSame(300, $config->cacheTtlBuffer);
    }

    #[Test]
    public function it_creates_config_with_all_custom_values(): void
    {
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
            timeout: 60,
            cacheTtlBuffer: 600,
        );

        $this->assertSame(self::TEST_APP_ID, $config->applicationId);
        $this->assertSame(self::TEST_APP_SECRET, $config->applicationSecret);
        $this->assertSame(self::TEST_INSTALL_TOKEN, $config->installationToken);
        $this->assertSame(60, $config->timeout);
        $this->assertSame(600, $config->cacheTtlBuffer);
    }

    #[Test]
    public function it_throws_exception_for_empty_application_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Linnworks application ID cannot be empty');

        new LinnworksConfig(
            applicationId: '',
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_application_secret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Linnworks application secret cannot be empty');

        new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: '',
            installationToken: self::TEST_INSTALL_TOKEN,
        );
    }

    #[Test]
    public function it_throws_exception_for_empty_installation_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Linnworks installation token cannot be empty');

        new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Numeric Parameter Boundary Tests (DataProvider)
    |--------------------------------------------------------------------------
    */

    /**
     * @param array{timeout?: int, cacheTtlBuffer?: int} $invalidConfig
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
            'applicationId' => self::TEST_APP_ID,
            'applicationSecret' => self::TEST_APP_SECRET,
            'installationToken' => self::TEST_INSTALL_TOKEN,
            'timeout' => 30,
            'cacheTtlBuffer' => 300,
            ...$invalidConfig,
        ];

        new LinnworksConfig(...$config);
    }

    /**
     * @return array<string, array{array<string, int>, class-string<Throwable>, string}>
     */
    public static function invalidNumericConfigs(): array
    {
        return [
            'timeout too low' => [
                ['timeout' => 0],
                InvalidArgumentException::class,
                'Timeout must be between 1-300 seconds, got 0',
            ],
            'timeout too high' => [
                ['timeout' => 301],
                InvalidArgumentException::class,
                'Timeout must be between 1-300 seconds, got 301',
            ],
            'cache TTL buffer negative' => [
                ['cacheTtlBuffer' => -1],
                InvalidArgumentException::class,
                'Cache TTL buffer must be between 0-3600 seconds, got -1',
            ],
            'cache TTL buffer too high' => [
                ['cacheTtlBuffer' => 3601],
                InvalidArgumentException::class,
                'Cache TTL buffer must be between 0-3600 seconds, got 3601',
            ],
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
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
            timeout: 1,
        );

        $this->assertSame(1, $config->timeout);
    }

    #[Test]
    public function it_accepts_timeout_at_maximum_boundary_of_300_seconds(): void
    {
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
            timeout: 300,
        );

        $this->assertSame(300, $config->timeout);
    }

    #[Test]
    public function it_accepts_cache_ttl_buffer_at_minimum_boundary_of_zero(): void
    {
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
            cacheTtlBuffer: 0,
        );

        $this->assertSame(0, $config->cacheTtlBuffer);
    }

    #[Test]
    public function it_accepts_cache_ttl_buffer_at_maximum_boundary_of_3600_seconds(): void
    {
        $config = new LinnworksConfig(
            applicationId: self::TEST_APP_ID,
            applicationSecret: self::TEST_APP_SECRET,
            installationToken: self::TEST_INSTALL_TOKEN,
            cacheTtlBuffer: 3600,
        );

        $this->assertSame(3600, $config->cacheTtlBuffer);
    }

    /*
    |--------------------------------------------------------------------------
    | Constants Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_auth_url_constant(): void
    {
        $this->assertSame(
            'https://api.linnworks.net/api/Auth/AuthorizeByApplication',
            LinnworksConfig::AUTH_URL,
        );
    }
}
