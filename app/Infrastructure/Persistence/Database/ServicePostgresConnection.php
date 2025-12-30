<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;

/**
 * PostgreSQL connection for service/admin operations.
 *
 * WARNING: This connection BYPASSES Row-Level Security (RLS).
 * All queries have unrestricted access to data across all users.
 * Only use when user-scoped access is explicitly NOT required.
 *
 * Sets empty RLS claims, which RLS policies treat as service context.
 *
 * Appropriate use cases:
 * - Background jobs that operate across users (e.g., reports, cleanup)
 * - Admin dashboard operations
 * - Migrations and seeders
 * - System maintenance tasks
 *
 * DO NOT use for:
 * - User-initiated requests (use `pgsql_rls` instead)
 * - Any operation where data should be scoped to a user
 *
 * Usage: `ProfileModel::on('pgsql_admin')->where(...)`
 *
 * @see RlsPostgresConnection For user-scoped queries with RLS enforcement
 * @see https://www.postgresql.org/docs/current/ddl-rowsecurity.html
 */
class ServicePostgresConnection extends PostgresConnection
{
    /**
     * Run a SQL statement with service context (empty claims).
     *
     * Sets empty `request.jwt.claims` session variable before each query.
     * RLS policies treat empty claims as service/admin context.
     *
     * @param  array<mixed>  $bindings
     *
     * @throws QueryException
     */
    protected function run(mixed $query, mixed $bindings, Closure $callback): mixed
    {
        // Session-scoped (is_local=false): empty claims = service context
        $this->statement("SELECT set_config('request.jwt.claims', '{}', false)");

        return parent::run($query, $bindings, $callback);
    }
}
