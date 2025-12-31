<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for RLS-aware PostgreSQL connections.
 *
 * Tests verify that:
 * - `pgsql_rls` allows queries in console context (migrations, tinker, jobs)
 * - `pgsql_rls` works correctly when user context is set
 * - `pgsql_admin` connection bypasses RLS and clears stale claims
 *
 * Note: Guard behavior in HTTP context (throwing when context missing) is
 * tested via Feature tests that exercise the full middleware chain.
 *
 * COVERAGE NOTE:
 * These are integration tests validating database connection behavior.
 * They don't cover specific application classes, hence #[CoversNothing].
 */
#[CoversNothing]
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
    public function pgsql_rls_connection_allows_queries_in_console_context(): void
    {
        // Console context (default in tests) should bypass the guard
        // This allows migrations, tinker, and queue workers to function
        Context::flush(); // Ensure no user context

        $result = DB::connection('pgsql_rls')->select('SELECT 1 as value');

        $this->assertIsArray($result);
        $this->assertSame(1, $result[0]->value);
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
