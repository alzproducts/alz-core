<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Supabase;

use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Supabase\SupabaseClient;
use Exception;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\TestCase;

#[CoversClass(SupabaseClient::class)]
final class SupabaseClientTest extends TestCase
{
    private LoggerInterface $mockLogger;

    private SupabaseClient $client;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createClient(bool $allowAllLogs = true): SupabaseClient
    {
        $this->mockLogger = Mockery::mock(LoggerInterface::class);

        if ($allowAllLogs) {
            $this->mockLogger->allows('warning');
            $this->mockLogger->allows('error');
        }

        return new SupabaseClient($this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Execution Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_operation_result(): void
    {
        $client = $this->createClient();

        $result = $client->execute(static fn(): string => 'success');

        $this->assertSame('success', $result);
    }

    #[Test]
    public function execute_returns_array_from_operation(): void
    {
        $client = $this->createClient();

        $result = $client->execute(static fn(): array => ['id' => 123, 'name' => 'test']);

        $this->assertSame(['id' => 123, 'name' => 'test'], $result);
    }

    #[Test]
    public function execute_returns_null_from_operation(): void
    {
        $client = $this->createClient();

        $result = $client->execute(static fn(): null => null);

        $this->assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | UniqueConstraintViolationException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_translates_unique_constraint_violation_to_duplicate_record(): void
    {
        $client = $this->createClient(allowAllLogs: false);

        $pdoException = new PDOException('Unique violation: duplicate key value violates unique constraint "users_email_unique" on relation "users"');
        $exception = new UniqueConstraintViolationException(
            'pgsql',
            'INSERT INTO users...',
            [],
            $pdoException,
        );

        $this->mockLogger->expects('warning')
            ->with('Unique constraint violation', Mockery::any())
            ->once();

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected DuplicateRecordException');
        } catch (DuplicateRecordException $e) {
            $this->assertSame('users', $e->table);
            $this->assertSame('users_email_unique', $e->constraint);
        }
    }

    #[Test]
    public function execute_extracts_table_name_from_relation_format(): void
    {
        $client = $this->createClient();

        $pdoException = new PDOException('duplicate key value violates unique constraint "orders_pk" on relation "orders"');
        $exception = new UniqueConstraintViolationException('pgsql', 'INSERT...', [], $pdoException);

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected DuplicateRecordException');
        } catch (DuplicateRecordException $e) {
            $this->assertSame('orders', $e->table);
            $this->assertSame('orders_pk', $e->constraint);
        }
    }

    #[Test]
    public function execute_returns_unknown_when_table_not_extractable(): void
    {
        $client = $this->createClient();

        $pdoException = new PDOException('Some error without proper format');
        $exception = new UniqueConstraintViolationException('pgsql', 'INSERT...', [], $pdoException);

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected DuplicateRecordException');
        } catch (DuplicateRecordException $e) {
            $this->assertSame('unknown', $e->table);
            $this->assertSame('unknown', $e->constraint);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LostConnectionException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_translates_lost_connection_to_external_service_unavailable(): void
    {
        $client = $this->createClient(allowAllLogs: false);
        $exception = new LostConnectionException('Connection lost');

        $this->mockLogger->expects('error')
            ->with('Database connection lost', Mockery::any())
            ->once();

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Supabase', $e->serviceName);
            $this->assertSame(30, $e->retryAfter);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DeadlockException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_translates_deadlock_to_external_service_unavailable(): void
    {
        $client = $this->createClient(allowAllLogs: false);
        $exception = new DeadlockException('Deadlock detected');

        $this->mockLogger->expects('warning')
            ->with('Database deadlock detected', Mockery::any())
            ->once();

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Supabase', $e->serviceName);
            $this->assertSame(5, $e->retryAfter);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | QueryException Tests - Transient Errors
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('transientErrorCodesProvider')]
    public function execute_translates_transient_query_errors_to_external_service_unavailable(string $sqlState): void
    {
        $client = $this->createClient(allowAllLogs: false);

        $pdoException = new PDOException('Query failed');
        $pdoException->errorInfo = [$sqlState, 0, 'Error message'];

        // Simulate PDO exception with proper code
        $reflection = new ReflectionClass($pdoException);
        $property = $reflection->getProperty('code');
        $property->setValue($pdoException, $sqlState);

        $queryException = new QueryException('pgsql', 'SELECT...', [], $pdoException);

        $this->mockLogger->expects('error')
            ->with('Database query failed', Mockery::any())
            ->once();

        try {
            $client->execute(static fn() => throw $queryException);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Supabase', $e->serviceName);
            $this->assertSame(10, $e->retryAfter);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transientErrorCodesProvider(): array
    {
        return [
            'deadlock' => ['40001'],
            'serialization failure' => ['40P01'],
            'query cancelled' => ['57014'],
            'admin shutdown 1' => ['57P01'],
            'admin shutdown 2' => ['57P02'],
            'admin shutdown 3' => ['57P03'],
            'connection exception' => ['08000'],
            'connection does not exist' => ['08003'],
            'connection failure' => ['08006'],
            'connection class error' => ['08001'], // Test 08xxx pattern
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | QueryException Tests - Permanent Errors
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_translates_permanent_query_errors_to_database_operation_failed(): void
    {
        $client = $this->createClient(allowAllLogs: false);

        $pdoException = new PDOException('Syntax error');
        $reflection = new ReflectionClass($pdoException);
        $property = $reflection->getProperty('code');
        $property->setValue($pdoException, '42601'); // Syntax error

        $queryException = new QueryException('pgsql', 'SELECT...', [], $pdoException);

        $this->mockLogger->expects('error')->once();

        try {
            $client->execute(static fn() => throw $queryException);
            $this->fail('Expected DatabaseOperationFailedException');
        } catch (DatabaseOperationFailedException $e) {
            $this->assertSame('query', $e->operation);
            $this->assertStringContainsString('Syntax error', $e->reason);
        }
    }

    #[Test]
    public function execute_translates_query_exception_without_sqlstate_to_database_operation_failed(): void
    {
        $client = $this->createClient(allowAllLogs: false);

        // QueryException without PDOException as previous
        $queryException = new QueryException('pgsql', 'SELECT...', [], new Exception('Unknown error'));

        $this->mockLogger->expects('error')->once();

        try {
            $client->execute(static fn() => throw $queryException);
            $this->fail('Expected DatabaseOperationFailedException');
        } catch (DatabaseOperationFailedException $e) {
            $this->assertSame('query', $e->operation);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PDOException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_translates_pdo_exception_to_external_service_unavailable(): void
    {
        $client = $this->createClient(allowAllLogs: false);
        $exception = new PDOException('Connection refused');

        $this->mockLogger->expects('error')
            ->with('PDO exception occurred', Mockery::any())
            ->once();

        try {
            $client->execute(static fn() => throw $exception);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Supabase', $e->serviceName);
            $this->assertSame(30, $e->retryAfter);
        }
    }
}
