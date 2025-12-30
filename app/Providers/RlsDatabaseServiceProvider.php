<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Persistence\Database\RlsPostgresConnection;
use App\Infrastructure\Persistence\Database\ServicePostgresConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Override;
use PDO;

/**
 * Registers custom PostgreSQL connection drivers for RLS enforcement.
 *
 * Two drivers are registered:
 * - `pgsql_rls`: For user-scoped queries (sets RLS claims from Laravel Context)
 * - `pgsql_admin`: For admin/service operations (empty claims bypass user RLS)
 *
 * @see RlsPostgresConnection
 * @see ServicePostgresConnection
 */
final class RlsDatabaseServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        Connection::resolverFor('pgsql_rls', static fn(
            PDO $pdo,
            string $database,
            string $prefix,
            array $config,
        ): RlsPostgresConnection => new RlsPostgresConnection($pdo, $database, $prefix, $config));

        Connection::resolverFor('pgsql_admin', static fn(
            PDO $pdo,
            string $database,
            string $prefix,
            array $config,
        ): ServicePostgresConnection => new ServicePostgresConnection($pdo, $database, $prefix, $config));
    }
}
