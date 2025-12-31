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
 * Console commands (migrate, tinker, queue workers) bypass the RLS guard since they
 * run without HTTP middleware context. Jobs needing user-scoped queries should
 * explicitly set Context::add('rls_user_id', $userId) before using pgsql_rls.
 *
 * @see SetRlsContextMiddleware
 */
final class RlsDatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Guard: pgsql_rls requires user context to be set by middleware
        // Skip for console commands (migrate, tinker, queue workers) - they have no middleware context
        DB::connection('pgsql_rls')->beforeExecuting(function (
            string $query,
            array $bindings,
            Connection $connection,
        ): void {
            // Console commands bypass RLS guard - authorization handled at dispatch level
            // Jobs needing user scope should explicitly set Context::add('rls_user_id', $userId)
            if ($this->app->runningInConsole()) {
                return;
            }

            $userId = Context::get('rls_user_id');

            if ($userId === null) {
                throw new RuntimeException(
                    'RLS user context not set. Ensure SetRlsContextMiddleware runs before database queries. '
                    . 'For admin operations, use DB::connection(\'pgsql_admin\') explicitly.',
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
