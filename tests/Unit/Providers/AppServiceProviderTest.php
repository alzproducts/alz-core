<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Providers\AppServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for AppServiceProvider.
 *
 * Note: CoversClass attribute removed because AppServiceProvider is excluded from
 * code coverage in phpunit.xml (boot-time validation, not runtime business logic).
 * PHPUnit 12 validates CoversClass targets must be in coverage scope.
 */
final class AppServiceProviderTest extends TestCase
{
    private AppServiceProvider $provider;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new AppServiceProvider($this->app);
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clean up environment variables
        \putenv('CI');
        \putenv('GITHUB_ACTIONS');
        parent::tearDown();
    }

    /**
     * Set up the environment for production validation checks.
     *
     * @param  array<string, mixed>  $configValues
     */
    private function setupProductionEnvironment(array $configValues = []): void
    {
        // Mock the application to be in 'production' environment
        $this->app->detectEnvironment(static fn(): string => 'production');

        // Ensure CI environment variables are not set
        \putenv('CI');
        \putenv('GITHUB_ACTIONS');

        // Set default valid config values
        $validConfig = [
            'app.key' => 'base64:' . \str_repeat('a', 32),
            'call-tracking.default_business_phone_number' => '+441234567000',
            'database.connections.pgsql.host' => 'localhost',
            'database.connections.pgsql.password' => 'password',
            'database.redis.default.host' => 'localhost',
            'database.redis.default.password' => 'password',
            'horizon.auth.username' => 'user',
            'horizon.auth.password' => 'password',
            'services.supabase.jwt_secret' => 'secret',
            'sentry.dsn' => 'https://test@sentry.io/123',
            'reviewsio.api_key' => 'test-api-key',
            'reviewsio.store_id' => 'test-store',
        ];

        // Allow overriding specific values for failure tests
        \config(\array_merge($validConfig, $configValues));
    }

    #[Test]
    public function boots_successfully_in_production_with_valid_configuration(): void
    {
        // Arrange
        $this->setupProductionEnvironment();

        // Act & Assert: No exception should be thrown
        $this->expectNotToPerformAssertions();
        $this->provider->boot();
    }

    #[DataProvider('nonProductionEnvironmentsProvider')]
    #[Test]
    public function skips_validation_in_non_production_environments(string $environment): void
    {
        // Arrange: Set a non-production environment and missing config
        $this->app->detectEnvironment(static fn(): string => $environment);
        \config(['app.key' => null]); // This would fail in production

        // Act & Assert: No exception should be thrown because validation was skipped
        $this->expectNotToPerformAssertions();
        $this->provider->boot();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonProductionEnvironmentsProvider(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
            'staging' => ['staging'],
        ];
    }

    #[DataProvider('ciEnvironmentsProvider')]
    #[Test]
    public function skips_validation_in_ci_environments(string $ciVariable): void
    {
        // Arrange: Set production env, but also a CI environment variable
        $this->app->detectEnvironment(static fn(): string => 'production');
        \putenv("{$ciVariable}=true");
        \config(['app.key' => null]); // This would fail if not skipped

        // Act & Assert: No exception should be thrown because validation was skipped
        $this->expectNotToPerformAssertions();
        $this->provider->boot();

        // Cleanup
        \putenv($ciVariable);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ciEnvironmentsProvider(): array
    {
        return [
            'CI variable' => ['CI'],
            'GITHUB_ACTIONS variable' => ['GITHUB_ACTIONS'],
        ];
    }

    #[DataProvider('missingConfigProvider')]
    #[Test]
    public function throws_exception_when_required_configuration_is_missing(string $missingKey, string $expectedMessageFragment): void
    {
        // Arrange: Set up production env but with one key missing
        $this->setupProductionEnvironment([$missingKey => null]);

        // Act & Assert
        try {
            $this->provider->boot();
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('Required configuration is missing or invalid', $e->getMessage());
            $this->assertStringContainsString($expectedMessageFragment, $e->detail);
            $this->assertStringContainsString('Production deployment blocked', $e->detail);
            $this->assertStringContainsString('Application cannot start safely', $e->detail);
            $this->assertStringContainsString('Please configure these variables', $e->detail);
            $this->assertStringContainsString('in your deployment environment', $e->detail);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingConfigProvider(): array
    {
        return [
            'missing app.key' => ['app.key', 'Application encryption key (APP_KEY)'],
            'missing default business phone' => ['call-tracking.default_business_phone_number', 'Default business phone number (DEFAULT_BUSINESS_PHONE_NUMBER)'],
            'missing db host' => ['database.connections.pgsql.host', 'Database host (DB_HOST)'],
            'missing db password' => ['database.connections.pgsql.password', 'Database password (DB_PASSWORD)'],
            'missing redis host' => ['database.redis.default.host', 'Redis host (REDIS_HOST)'],
            'missing redis password' => ['database.redis.default.password', 'Redis password (REDIS_PASSWORD)'],
            'missing horizon user' => ['horizon.auth.username', 'Horizon dashboard username (HORIZON_USER)'],
            'missing horizon password' => ['horizon.auth.password', 'Horizon dashboard password (HORIZON_PASSWORD)'],
            'missing supabase secret' => ['services.supabase.jwt_secret', 'Supabase JWT secret (SUPABASE_JWT_SECRET)'],
            'missing sentry dsn' => ['sentry.dsn', 'Sentry DSN (SENTRY_LARAVEL_DSN)'],
        ];
    }

    #[DataProvider('emptyConfigProvider')]
    #[Test]
    public function throws_exception_when_required_configuration_is_empty(string $emptyKey, mixed $emptyValue): void
    {
        // Arrange
        $this->setupProductionEnvironment([$emptyKey => $emptyValue]);

        // Act & Assert
        try {
            $this->provider->boot();
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('Required configuration is missing or invalid', $e->getMessage());
            $this->assertStringContainsString('Production deployment blocked', $e->detail);
        }
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function emptyConfigProvider(): array
    {
        return [
            'empty string app.key' => ['app.key', ''],
            'false db host' => ['database.connections.pgsql.host', false],
            'empty string redis password' => ['database.redis.default.password', ''],
        ];
    }

    #[DataProvider('invalidAppKeyProvider')]
    #[Test]
    public function throws_exception_when_app_key_is_invalid(mixed $invalidKey): void
    {
        // Arrange
        $this->setupProductionEnvironment(['app.key' => $invalidKey]);

        // Act & Assert
        try {
            $this->provider->boot();
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('Required configuration is missing or invalid', $e->getMessage());
            $this->assertStringContainsString('SECURITY: APP_KEY is too short or invalid.', $e->detail);
            $this->assertStringContainsString("Run 'php artisan key:generate' to create a secure key.", $e->detail);
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAppKeyProvider(): array
    {
        return [
            'key too short' => ['base64:' . \str_repeat('a', 10)],
            'key with only 31 chars' => [\str_repeat('a', 31)],
        ];
    }

    #[Test]
    public function throws_exception_listing_multiple_missing_configs(): void
    {
        // Arrange
        $this->setupProductionEnvironment([
            'app.key' => null,
            'database.connections.pgsql.host' => '',
            'services.supabase.jwt_secret' => false,
        ]);

        // Act & Assert
        try {
            $this->provider->boot();
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('Required configuration is missing or invalid', $e->getMessage());
            $this->assertStringContainsString('Application encryption key (APP_KEY)', $e->detail);
            $this->assertStringContainsString('Database host (DB_HOST)', $e->detail);
            $this->assertStringContainsString('Supabase JWT secret (SUPABASE_JWT_SECRET)', $e->detail);
        }
    }

    #[Test]
    public function accepts_app_key_with_exactly_32_characters(): void
    {
        // Arrange: APP_KEY with exactly 32 chars (boundary condition)
        $this->setupProductionEnvironment(['app.key' => \str_repeat('a', 32)]);

        // Act & Assert: No exception should be thrown
        $this->expectNotToPerformAssertions();
        $this->provider->boot();
    }

    #[Test]
    public function accepts_app_key_longer_than_32_characters(): void
    {
        // Arrange: APP_KEY with 64 chars (well above minimum)
        $this->setupProductionEnvironment(['app.key' => \str_repeat('a', 64)]);

        // Act & Assert: No exception should be thrown
        $this->expectNotToPerformAssertions();
        $this->provider->boot();
    }
}
