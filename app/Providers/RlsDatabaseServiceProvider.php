<?php

declare(strict_types=1);

namespace App\Providers;

use App\Presentation\Http\Middleware\SetRlsContextMiddleware;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers beforeExecuting callbacks for RLS enforcement on PostgreSQL connections.
 *
 * Two connections are configured:
 * - `pgsql_rls`: Guards against missing user context (throws if Context::get('rls_user_id') is null)
 * - `pgsql_admin`: Clears stale RLS claims from previous requests (Octane safety)
 *
 * The middleware (SetRlsContextMiddleware) sets the PostgreSQL session variable once per request.
 * These callbacks act as safety guards, not the primary mechanism.
 *
 * SECURITY: pgsql_rls ALWAYS requires user context - no exceptions.
 * - HTTP requests: SetRlsContextMiddleware sets context automatically
 * - Queue jobs: Must explicitly set Context::add('rls_user_id', $userId) before queries
 * - Migrations/seeders: Use 'pgsql' connection (configured in database.php)
 * - Admin operations: Use 'pgsql_admin' connection explicitly
 *
 * @see SetRlsContextMiddleware
 */
final class RlsDatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Guard: pgsql_rls ALWAYS requires user context - secure by default
        // No console bypass: queue workers must explicitly set context or use different connection
        DB::connection('pgsql_rls')->beforeExecuting(static function (
            string $query,
            array $bindings,
            Connection $connection,
        ): void {
            $userId = Context::get('rls_user_id');

            if ($userId === null) {
                throw new RuntimeException(
                    'RLS user context not set. Either:'
                    . ' (1) Set Context::add(\'rls_user_id\', $userId) before queries, or'
                    . ' (2) Use DB::connection(\'pgsql\') for migrations/seeders, or'
                    . ' (3) Use DB::connection(\'pgsql_admin\') for admin operations.',
                );
            }
        });

        // Safety: pgsql_admin clears any stale RLS claims from previous Octane requests
        // Uses raw PDO to avoid triggering beforeExecuting recursion
        // IMPORTANT: No static caching - must run every query to handle Octane request boundaries
        DB::connection('pgsql_admin')->beforeExecuting(static function (
            string $query,
            array $bindings,
            Connection $connection,
        ): void {
            // Use raw PDO to avoid recursion (statement() would trigger beforeExecuting again)
            // set_config is very fast - no need to optimize with request-scoped caching
            $connection->getPdo()->exec("SELECT set_config('request.jwt.claims', '{}', false)");
        });
    }
}
