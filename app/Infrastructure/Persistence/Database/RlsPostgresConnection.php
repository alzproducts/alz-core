<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use JsonException;
use RuntimeException;

/**
 * PostgreSQL connection with automatic RLS (Row-Level Security) context.
 *
 * Every query executed through this connection automatically sets the
 * `request.jwt.claims` session variable that RLS policies use to identify
 * the current user. The user ID is read from Laravel's Context facade,
 * which is set by middleware at the start of each request.
 *
 * For admin/service operations that should bypass user-specific RLS,
 * use the `pgsql_service` connection instead.
 *
 * @see https://www.postgresql.org/docs/current/ddl-rowsecurity.html
 */
class RlsPostgresConnection extends PostgresConnection
{
    /**
     * Run a SQL statement with RLS context.
     *
     * Sets `request.jwt.claims` session variable before each query.
     * Uses session-scoped setting (is_local=false) which persists across
     * statements and gets overwritten at the start of each call.
     *
     * @param  array<mixed>  $bindings
     *
     * @throws RuntimeException If no user ID is set in Context
     * @throws JsonException If user ID cannot be JSON encoded
     * @throws QueryException
     */
    protected function run(mixed $query, mixed $bindings, Closure $callback): mixed
    {
        $userId = Context::get('rls_user_id');

        if ($userId === null) {
            throw new RuntimeException(
                'RLS user context not set. Either set Context::add(\'rls_user_id\', $userId) '
                . 'or use the pgsql_service connection for admin operations.',
            );
        }

        $claims = \json_encode(['sub' => $userId], JSON_THROW_ON_ERROR);

        // Session-scoped (is_local=false): persists for the connection session.
        // Safe with Octane because we overwrite at the start of every query.
        $this->statement("SELECT set_config('request.jwt.claims', ?, false)", [$claims]);

        return parent::run($query, $bindings, $callback);
    }
}
