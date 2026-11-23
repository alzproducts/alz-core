<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Infrastructure\Database\DatabaseConnectivityClient;
use App\Presentation\Console\Commands\DatabasePingCommand;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(DatabasePingCommand::class)]
#[CoversClass(DatabaseConnectivityClient::class)]
final class DatabasePingCommandTest extends TestCase
{
    #[Test]
    public function a_reachable_connection_exits_successfully(): void
    {
        \config()->set('database.connections.ping_ok', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->artisan('db:ping', ['connection' => 'ping_ok'])
            ->expectsOutputToContain('Database connection OK')
            ->assertSuccessful();
    }

    #[Test]
    public function an_unreachable_connection_fails_with_the_driver_error(): void
    {
        \config()->set('database.connections.ping_down', [
            'driver' => 'pgsql',
            'host' => 'db-unreachable.invalid',
            'port' => '5432',
            'database' => 'none',
            'username' => 'none',
            'password' => '',
            'options' => [PDO::ATTR_TIMEOUT => 2],
        ]);

        $this->artisan('db:ping', ['connection' => 'ping_down'])
            ->expectsOutputToContain('db-unreachable.invalid')
            ->assertFailed();
    }

    #[Test]
    public function an_unconfigured_connection_fails_with_its_name(): void
    {
        $this->artisan('db:ping', ['connection' => 'ping_missing'])
            ->expectsOutputToContain('Database connection [ping_missing] not configured')
            ->assertFailed();
    }
}
