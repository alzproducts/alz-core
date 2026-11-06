<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Providers\AppServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A local or testing boot fails on a database, Redis or Supabase host that is neither loopback nor allowlisted,
 * naming the setting (AC8). Other environments are not guarded.
 */
final class LocalEnvironmentGuardTest extends TestCase
{
    private const string REMOTE_HOST = 'db.example.supabase.co';

    private const array PGSQL_CONNECTIONS = ['pgsql', 'pgsql_rls', 'pgsql_admin'];

    private const array REDIS_CONNECTIONS = ['default', 'cache', 'queue'];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config([
            'integrations.local_allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],
            'integrations.live.supabase' => false,
            'services.supabase.url' => 'http://127.0.0.1:54321',
        ]);
        $this->setHosts('127.0.0.1');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function remoteSettings(): iterable
    {
        yield 'DB_HOST' => [['database.connections.pgsql.host' => self::REMOTE_HOST], 'DB_HOST'];
        yield 'DB_HOST on the admin connection' => [['database.connections.pgsql_admin.host' => self::REMOTE_HOST], 'DB_HOST'];
        yield 'DB_URL' => [['database.connections.pgsql_rls.url' => 'postgresql://postgres:secret@' . self::REMOTE_HOST . ':5432/postgres'], 'DB_URL'];
        yield 'REDIS_HOST' => [['database.redis.default.host' => 'redis.example.com'], 'REDIS_HOST'];
        yield 'REDIS_HOST on the queue connection' => [['database.redis.queue.host' => 'redis.example.com'], 'REDIS_HOST'];
        yield 'REDIS_URL' => [['database.redis.cache.url' => 'rediss://default:secret@redis.example.com:6379'], 'REDIS_URL'];
        yield 'SUPABASE_URL' => [['services.supabase.url' => 'https://abcdefgh.supabase.co'], 'SUPABASE_URL'];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[Test]
    #[DataProvider('remoteSettings')]
    public function local_and_testing_boots_refuse_a_remote_host_naming_the_setting(array $overrides, string $configKey): void
    {
        \config($overrides);

        foreach (['local', 'testing'] as $environment) {
            $this->assertBootRefuses($environment, $configKey);
        }
    }

    #[Test]
    public function loopback_hosts_and_urls_boot(): void
    {
        \config([
            'database.connections.pgsql.host' => 'localhost',
            'database.connections.pgsql_admin.host' => '::1',
            'database.redis.queue.url' => 'redis://[::1]:6379',
            'services.supabase.url' => 'http://localhost:54321',
        ]);

        $this->expectNotToPerformAssertions();
        $this->boot('local');
    }

    #[Test]
    public function a_url_wins_over_its_host(): void
    {
        $this->setHosts(self::REMOTE_HOST);
        foreach (self::PGSQL_CONNECTIONS as $connection) {
            \config(["database.connections.{$connection}.url" => 'postgresql://postgres:secret@127.0.0.1:54322/postgres']);
        }
        foreach (self::REDIS_CONNECTIONS as $connection) {
            \config(["database.redis.{$connection}.url" => 'redis://127.0.0.1:6379']);
        }

        $this->expectNotToPerformAssertions();
        $this->boot('testing');
    }

    #[Test]
    public function hosts_in_the_local_allowlist_boot(): void
    {
        \config([
            'integrations.local_allowed_hosts' => ['127.0.0.1', 'localhost', '::1', 'pgsql', 'redis', 'supabase-kong'],
            'services.supabase.url' => 'http://supabase-kong:8000',
        ]);
        $this->setHosts('pgsql', 'redis');

        $this->expectNotToPerformAssertions();
        $this->boot('local');
    }

    #[Test]
    public function the_supabase_opt_in_exempts_a_remote_supabase_url(): void
    {
        \config([
            'integrations.live.supabase' => true,
            'services.supabase.url' => 'https://abcdefgh.supabase.co',
        ]);

        $this->expectNotToPerformAssertions();
        $this->boot('local');
    }

    #[Test]
    public function the_supabase_opt_in_does_not_exempt_the_database(): void
    {
        \config([
            'integrations.live.supabase' => true,
            'database.connections.pgsql.host' => self::REMOTE_HOST,
        ]);

        $this->assertBootRefuses('local', 'DB_HOST');
    }

    #[Test]
    public function staging_and_production_are_not_guarded(): void
    {
        $this->setHosts(self::REMOTE_HOST, 'redis.example.com');
        \config(['services.supabase.url' => 'https://abcdefgh.supabase.co']);
        $ci = \getenv('CI');
        // intentional: CI skips the production config check, isolating this assertion to the local guard.
        \putenv('CI=true');
        $this->expectNotToPerformAssertions();

        try {
            $this->boot('staging');
            $this->boot('production');
        } finally {
            \putenv($ci === false ? 'CI' : "CI={$ci}");
        }
    }

    private function assertBootRefuses(string $environment, string $configKey): void
    {
        try {
            $this->boot($environment);
        } catch (InvalidConfigurationException $e) {
            $this->assertSame($configKey, $e->configKey, $environment);

            return;
        }

        $this->fail("Expected a {$environment} boot to refuse {$configKey}.");
    }

    private function boot(string $environment): void
    {
        $this->app->detectEnvironment(static fn(): string => $environment);

        new AppServiceProvider($this->app)->boot();
    }

    private function setHosts(string $databaseHost, ?string $redisHost = null): void
    {
        foreach (self::PGSQL_CONNECTIONS as $connection) {
            \config(["database.connections.{$connection}.host" => $databaseHost, "database.connections.{$connection}.url" => null]);
        }

        foreach (self::REDIS_CONNECTIONS as $connection) {
            \config(["database.redis.{$connection}.host" => $redisHost ?? $databaseHost, "database.redis.{$connection}.url" => null]);
        }
    }
}
