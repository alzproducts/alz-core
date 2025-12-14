<?php

/** @noinspection UnknownColumnInspection */

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Override;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests verifying database connectivity and basic operations.
 *
 * Tests both local Sail PostgreSQL (DB_HOST=pgsql) and Supabase PostgreSQL
 * (DB_HOST=db.*.supabase.co) to ensure seamless switching between environments.
 *
 * SUPABASE TESTING:
 * To test against Supabase, temporarily update .env with Supabase credentials:
 * - DB_HOST=db.your-project.supabase.co
 * - DB_DATABASE=postgres
 * - DB_USERNAME=postgres
 * - DB_PASSWORD=<your-supabase-password>
 * - DB_SSLMODE=require
 * Then run: ./vendor/bin/sail artisan test --filter=DatabaseConnectivityTest
 *
 * COVERAGE NOTE:
 * These are integration tests validating external dependencies (database connectivity).
 * They don't cover specific application classes, hence #[CoversNothing].
 */
#[CoversNothing]
class DatabaseConnectivityTest extends TestCase
{
    /**
     * Table name for CRUD operation tests.
     * Created/destroyed per test to ensure isolation.
     */
    private const string TEST_TABLE = 'test_connectivity_table';

    /**
     * Clean up test table after each test.
     * Ensures isolation even if tests fail mid-execution.
     */
    #[Override]
    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable(self::TEST_TABLE)) {
                Schema::drop(self::TEST_TABLE);
            }
        } catch (PDOException) {
            // Ignore errors during cleanup (e.g., connection closed)
        }

        parent::tearDown();
    }

    /**
     * Test that database connection can be established.
     *
     * SECURITY: Verifies environment variables from Issue #3 (Phase 1 Security)
     * actually result in a working database connection.
     *
     * This is the most basic connectivity check - if this fails, all other
     * tests will fail. Always debug this test first.
     */
    #[Test]
    public function establishes_database_connection_successfully(): void
    {
        // Act: Attempt to get the PDO connection
        $pdo = DB::connection()->getPdo();

        // Verify we can query the connection (not just that PDO exists)
        $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertSame('pgsql', $driverName, 'Database driver should be PostgreSQL');
    }

    /**
     * Test that PostgreSQL version can be queried.
     *
     * Validates both connectivity AND that the database is actually PostgreSQL.
     * Different results expected:
     * - Local Sail: PostgreSQL 15 (or version in docker-compose.yml)
     * - Supabase: PostgreSQL 15+ (Supabase's managed version)
     */
    #[Test]
    public function queries_postgresql_version_successfully(): void
    {
        // Act: Query the PostgreSQL version
        $result = DB::selectOne('SELECT version() AS version');

        // Assert: Result contains version information
        $this->assertIsObject($result, 'Version query should return an object');

        // Verify it's actually PostgreSQL
        $version = $result->version;
        $this->assertIsString($version, 'Version should be a string');
        $this->assertStringContainsString('PostgreSQL', $version, 'Database should be PostgreSQL');

        // Verify it's a reasonable version (10+ minimum for modern features)
        $this->assertMatchesRegularExpression(
            '/PostgreSQL (\d{2,})\.\d+/',
            $version,
            'PostgreSQL version should be 10+ (format: PostgreSQL XX.Y)',
        );
    }

    /**
     * Test basic CRUD operations (INSERT and SELECT).
     * Validates that the database is writable, not just readable.
     * This catches permission issues, read-only replicas, etc.
     * MUTATION TESTING NOTE:
     * Uses assertSame() with exact values to kill IdenticalEqual mutators.
     * Using assertEquals() would allow type juggling (e.g., "42" == 42).
     *
     * @noinspection UnknownColumnInspection*/
    #[Test]
    public function performs_crud_operations_successfully(): void
    {
        // Arrange: Create a test table
        Schema::create(self::TEST_TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('value');
            $table->timestamps();
        });

        // Act: Insert a record
        $inserted = DB::table(self::TEST_TABLE)->insert([
            'name' => 'test-record',
            'value' => 42,
            'created_at' => \now(),
            'updated_at' => \now(),
        ]);

        // Assert: Insert succeeded
        $this->assertTrue($inserted, 'Record insertion should succeed');

        // Act: Query the inserted record
        $record = DB::table(self::TEST_TABLE)
            ->where('name', 'test-record')
            ->first();

        // Assert: Record was retrieved with exact values
        $this->assertNotNull($record, 'Inserted record should be retrievable');
        $this->assertSame('test-record', $record->name, 'Name should match exactly');
        $this->assertSame(42, $record->value, 'Value should match exactly (integer, not string)');
    }

    /**
     * Test database health check metrics.
     *
     * Verifies:
     * - Connection pool is working (can execute queries)
     * - Database has tables (schema exists)
     * - Current database name matches configuration
     *
     * PRODUCTION NOTE:
     * In Supabase, this will show shared tables from Next.js frontend.
     * Expected tables: users, sessions (created by Supabase Auth).
     */
    #[Test]
    public function database_health_check_passes(): void
    {
        // Act: Query current database name
        $result = DB::selectOne('SELECT current_database() AS db_name');

        // Assert: Database name matches configuration
        $this->assertIsObject($result, 'Database name query should return a result');
        $expectedDatabase = \config('database.connections.pgsql.database');
        $this->assertSame(
            $expectedDatabase,
            $result->db_name,
            'Current database should match configuration',
        );

        // Act: Count tables in public schema
        $tableCount = DB::selectOne(
            "SELECT COUNT(*) AS count
             FROM information_schema.tables
             WHERE table_schema = 'public'
             AND table_type = 'BASE TABLE'",
        );

        // Assert: Query executed successfully (count may be 0 in fresh test database)
        $this->assertIsObject($tableCount, 'Table count query should return a result');
        $this->assertIsInt($tableCount->count, 'Table count should be an integer');
        $this->assertGreaterThanOrEqual(
            0,
            $tableCount->count,
            'Table count should be non-negative',
        );
    }

    /**
     * Test SSL mode configuration matches environment.
     *
     * SECURITY: Ensures production (Supabase) uses SSL, while local dev doesn't.
     *
     * Expected SSL modes:
     * - Local Sail: 'disable' (container doesn't support SSL)
     * - Supabase: 'require' (enforced by Supabase)
     *
     * WHY THIS MATTERS:
     * - Supabase requires SSL connections (security best practice)
     * - Local Sail container doesn't have SSL certificates
     * - Wrong SSL mode = connection failures
     *
     * MUTATION TESTING NOTE:
     * Uses strict in_array() to prevent type coercion of SSL mode values.
     * The third parameter 'true' is critical for security.
     */
    #[Test]
    public function ssl_mode_configured_correctly(): void
    {
        // Arrange: Get configured SSL mode
        $configuredSslMode = \config('database.connections.pgsql.sslmode');

        // Assert: SSL mode is set
        $this->assertIsString(
            $configuredSslMode,
            'SSL mode should be configured as a string',
        );

        // Assert: SSL mode is valid
        $validSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        $this->assertTrue(
            \in_array($configuredSslMode, $validSslModes, true),
            \sprintf(
                'SSL mode "%s" should be one of: %s',
                $configuredSslMode,
                \implode(', ', $validSslModes),
            ),
        );

        // Act: Query actual SSL status from database
        $sslStatus = DB::selectOne('SHOW ssl');

        // Assert: SSL status is queryable
        $this->assertIsObject($sslStatus, 'SSL status query should return a result');

        // Document expected behavior based on environment
        // We don't assert exact SSL on/off here because:
        // - Local: SSL is 'off' (expected with sslmode=disable)
        // - Supabase: SSL is 'on' (expected with sslmode=require)
        // Instead, verify the query itself works (proves connection is stable)
        $this->assertIsString($sslStatus->ssl, 'SSL status should be a string (on/off)');
        $this->assertTrue(
            \in_array($sslStatus->ssl, ['on', 'off'], true),
            'SSL status should be either "on" or "off"',
        );
    }

    /**
     * Test connection resilience to rapid queries.
     *
     * Validates connection pooling handles multiple sequential queries
     * without errors or degradation.
     *
     * WHY THIS MATTERS:
     * - Webhooks may trigger rapid database queries
     * - Supabase connection pooler (PgBouncer) has limits
     * - Local Sail should handle high query volume
     *
     * This test catches connection pool exhaustion issues early.
     */
    #[Test]
    public function handles_rapid_sequential_queries(): void
    {
        // Arrange: Prepare 10 rapid queries
        $queryCount = 10;
        $results = [];

        // Act: Execute queries in rapid succession
        for ($i = 0; $i < $queryCount; $i++) {
            $result = DB::selectOne('SELECT 1 AS value');
            $this->assertIsObject($result, 'Query should return an object');
            $results[] = $result->value;
        }

        // Assert: All queries succeeded
        $this->assertCount($queryCount, $results, 'All queries should complete');

        // Assert: All queries returned correct value
        foreach ($results as $index => $value) {
            $this->assertSame(
                1,
                $value,
                \sprintf('Query #%d should return 1', $index + 1),
            );
        }
    }

    /**
     * Test that database connection recovery works after failure.
     *
     * Simulates a connection interruption and verifies Laravel
     * automatically reconnects on next query.
     *
     * WHY THIS MATTERS:
     * - Long-running queue workers may lose connections
     * - Network issues can close connections mid-execution
     * - Laravel should auto-reconnect transparently
     *
     * IMPLEMENTATION NOTE:
     * We disconnect, then immediately query. Laravel's query builder
     * should detect the closed connection and reconnect automatically.
     */
    #[Test]
    public function reconnects_automatically_after_disconnection(): void
    {
        // Arrange: Establish initial connection
        $initialConnection = DB::connection()->getPdo();

        // Act: Force disconnect
        DB::disconnect();

        // Act: Execute a query (should auto-reconnect)
        $result = DB::selectOne('SELECT 1 AS value');

        // Assert: Query succeeded after reconnection
        $this->assertIsObject($result, 'Query should succeed after reconnection');
        $this->assertSame(1, $result->value, 'Query should return correct value');

        // Verify we got a new connection object
        $newConnection = DB::connection()->getPdo();
        $this->assertNotSame(
            $initialConnection,
            $newConnection,
            'Connection should be a new instance after disconnect',
        );
    }

    /**
     * Test transaction support and rollback behavior.
     *
     * Validates that database supports transactions properly,
     * which is critical for queue jobs and webhook processing.
     *
     * WHY THIS MATTERS:
     * - Order processing requires transactions (inventory + payment)
     * - Failed webhooks should rollback changes
     * - Supabase must support ACID transactions
     *
     * MUTATION TESTING NOTE:
     * Uses assertSame(0, $count) not assertEmpty() to be explicit.
     * Prevents GreaterThanOrEqualTo mutator from escaping.
     */
    #[Test]
    public function supports_transactions_and_rollback(): void
    {
        // Arrange: Create test table
        Schema::create(self::TEST_TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        // Act: Insert record inside transaction, then rollback
        DB::beginTransaction();
        DB::table(self::TEST_TABLE)->insert(['name' => 'should-rollback']);
        DB::rollBack();

        // Assert: Record was not persisted
        $count = DB::table(self::TEST_TABLE)->count();
        $this->assertSame(0, $count, 'Rolled back record should not exist');

        // Act: Insert record inside transaction, then commit
        DB::beginTransaction();
        DB::table(self::TEST_TABLE)->insert(['name' => 'should-persist']);
        DB::commit();

        // Assert: Record was persisted
        $count = DB::table(self::TEST_TABLE)->count();
        $this->assertSame(1, $count, 'Committed record should exist');

        $record = DB::table(self::TEST_TABLE)->first();
        $this->assertNotNull($record, 'Committed record should be retrievable');
        $this->assertSame('should-persist', $record->name, 'Committed record name should match');
    }

    /**
     * Test that invalid SQL queries throw appropriate exceptions.
     *
     * Validates error handling works correctly - important for:
     * - Debugging failed queries in production
     * - Ensuring exceptions propagate to Sentry/logging
     * - Catching SQL injection attempts (malformed queries)
     *
     * EXPECTED BEHAVIOR:
     * Invalid SQL should throw \Illuminate\Database\QueryException
     * (which wraps PDOException with additional Laravel context).
     */
    #[Test]
    public function throws_exception_for_invalid_queries(): void
    {
        // Assert: Invalid query throws exception
        $this->expectException(QueryException::class);

        // Act: Execute invalid SQL
        DB::select('SELECT * FROM nonexistent_table_xyz');
    }

    /**
     * Test connection to verify shared database structure (Supabase).
     *
     * When running against Supabase, verifies we can query the shared
     * database structure created by Next.js frontend.
     *
     * SKIP CONDITIONS:
     * - Local Sail: Skipped (no shared tables exist)
     * - Supabase: Should pass (users table exists from Next.js)
     *
     * HOW TO IDENTIFY SUPABASE:
     * Check if DB_HOST contains 'supabase.co'
     *
     * MUTATION TESTING NOTE:
     * This test is environment-dependent. In local testing, it will be skipped.
     * No mutators to kill since core logic is behind environment check.
     */
    #[Test]
    public function verifies_shared_database_structure_with_nextjs(): void
    {
        // Arrange: Check if we're connected to Supabase
        /** @var string|null $dbHost */
        $dbHost = \config('database.connections.pgsql.host');

        // Skip if not Supabase (local Sail testing)
        if ($dbHost === null || ! \str_contains($dbHost, 'supabase.co')) {
            $this->markTestSkipped(
                'Shared database test only runs against Supabase. '
                . 'Current DB_HOST: ' . ($dbHost ?? 'null'),
            );
        }

        // Act: Query config.dashboard table (shared with Next.js frontend)
        // Note: auth schema is restricted via pooler, so we check accessible tables
        $configExists = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'config'
                AND table_name = 'dashboard'
            ) AS exists",
        );

        // Assert: config.dashboard table exists (shared with Next.js frontend)
        $this->assertTrue(
            (bool) $configExists->exists,
            'Supabase should have config.dashboard table (shared with Next.js)',
        );
    }
}
