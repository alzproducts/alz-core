<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Integration tests for RLS-aware PostgreSQL connections.
 *
 * Tests verify that:
 * - `pgsql_rls` throws RuntimeException when user context is missing (secure by default)
 * - `pgsql_rls` works correctly when user context is set
 * - `pgsql_admin` connection bypasses RLS and clears stale claims
 *
 * SECURITY: pgsql_rls ALWAYS requires user context - no exceptions.
 * Use 'pgsql' connection for migrations/seeders, 'pgsql_admin' for admin operations.
 *
 * COVERAGE NOTE:
 * These are integration tests validating database connection behavior.
 * They don't cover specific application classes, hence #[CoversNothing].
 */
#[CoversNothing]
#[Group('integration')]
class RlsConnectionTest extends TestCase
{
    /**
     * Clear Laravel Context before each test for isolation.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Context::flush();
    }

    #[Test]
    public function pgsql_rls_connection_throws_when_user_context_missing(): void
    {
        // SECURITY: pgsql_rls ALWAYS requires user context - no exceptions.
        // This prevents accidental data access without proper RLS enforcement.
        // Use 'pgsql' for migrations/seeders, 'pgsql_admin' for admin operations.
        Context::flush(); // Ensure no user context

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RLS user context not set');

        DB::connection('pgsql_rls')->select('SELECT 1 as value');
    }

    #[Test]
    public function pgsql_rls_connection_executes_queries_when_user_context_is_set(): void
    {
        Context::add('rls_user_id', 'test-user-uuid');

        $result = DB::connection('pgsql_rls')->select('SELECT 1 as value');

        $this->assertIsArray($result);
        $this->assertSame(1, $result[0]->value);
    }

    #[Test]
    public function pgsql_admin_connection_executes_without_user_context(): void
    {
        // Ensure no context is set
        Context::flush();

        $result = DB::connection('pgsql_admin')->select('SELECT 1 as value');

        $this->assertIsArray($result);
        $this->assertSame(1, $result[0]->value);
    }

    #[Test]
    public function pgsql_admin_connection_sets_empty_jwt_claims(): void
    {
        // Execute a query to trigger the set_config
        DB::connection('pgsql_admin')->select('SELECT 1');

        // Check the session variable was set to empty object
        $result = DB::connection('pgsql_admin')
            ->select("SELECT current_setting('request.jwt.claims', true) as claims");

        $this->assertSame('{}', $result[0]->claims);
    }
}
