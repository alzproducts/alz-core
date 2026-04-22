<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Database;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Closure;
use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Integration tests for DatabaseGateway exception translation.
 *
 * Tests the boundary behavior: database exceptions → domain exceptions.
 * Per TestingStrategy.md: one happy path, key error paths only.
 */
#[CoversClass(DatabaseGateway::class)]
#[Group('integration')]
final class DatabaseGatewayTest extends TestCase
{
    private LoggerInterface&MockInterface $mockLogger;

    private DatabaseManager&MockInterface $mockDb;

    private function createGateway(): DatabaseGateway
    {
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->allows('info');
        $this->mockLogger->allows('warning');
        $this->mockLogger->allows('error');

        $this->mockDb = Mockery::mock(DatabaseManager::class);

        return new DatabaseGateway($this->mockLogger, $this->mockDb);
    }

    #[Test]
    public function query_returns_operation_result(): void
    {
        $gateway = $this->createGateway();

        $result = $gateway->query(static fn(): array => ['id' => 123, 'status' => 'active']);

        $this->assertSame(['id' => 123, 'status' => 'active'], $result);
    }

    #[Test]
    public function query_translates_transient_error_to_external_service_unavailable(): void
    {
        $gateway = $this->createGateway();

        $this->expectException(ExternalServiceUnavailableException::class);

        $gateway->query(static fn() => throw new LostConnectionException('Connection lost'));
    }

    #[Test]
    public function query_translates_permanent_error_to_database_operation_failed(): void
    {
        $gateway = $this->createGateway();

        $queryException = new QueryException('pgsql', 'SELECT...', [], new Exception('Syntax error'));

        $this->expectException(DatabaseOperationFailedException::class);

        $gateway->query(static fn() => throw $queryException);
    }

    #[Test]
    public function query_translates_unique_constraint_to_duplicate_record(): void
    {
        $gateway = $this->createGateway();

        $pdoException = new PDOException('duplicate key violates unique constraint "orders_pk" on relation "orders"');
        $exception = new UniqueConstraintViolationException('pgsql', 'INSERT...', [], $pdoException);

        $this->expectException(DuplicateRecordException::class);

        $gateway->query(static fn() => throw $exception);
    }

    #[Test]
    public function query_translates_connection_timeout_with_integer_pdo_code_to_transient(): void
    {
        $gateway = $this->createGateway();

        // PDO::connect() failures return integer driver code, not SQLSTATE string.
        // The SQLSTATE is only in the message: "SQLSTATE[08006] [7] connection ... timeout"
        $pdoException = new PDOException(
            'SQLSTATE[08006] [7] connection to server at "pooler.supabase.com", port 5432 failed: timeout expired',
        );
        // PDOException::$code is declared as string but PHP's PDO driver
        // internally sets it to the integer driver error code for connection failures.
        $reflection = new ReflectionProperty(PDOException::class, 'code');
        $reflection->setValue($pdoException, 7);

        $queryException = new QueryException('pgsql', 'SELECT * FROM "sync_cursors" LIMIT 1', [], $pdoException);

        $this->expectException(ExternalServiceUnavailableException::class);

        $gateway->query(static fn() => throw $queryException);
    }

    #[Test]
    public function transact_wraps_operation_in_database_transaction(): void
    {
        $gateway = $this->createGateway();

        $this->mockDb->shouldReceive('transaction')
            ->once()
            ->withArgs(static fn(Closure $callback, int $attempts): bool => $attempts === 1)
            ->andReturnUsing(static fn(Closure $callback): mixed => $callback());

        $result = $gateway->transact(static fn(): array => ['id' => 456]);

        $this->assertSame(['id' => 456], $result);
    }
}
