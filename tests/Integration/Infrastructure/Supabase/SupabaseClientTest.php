<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Supabase;

use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Supabase\SupabaseClient;
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
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Integration tests for SupabaseClient exception translation.
 *
 * Tests the boundary behavior: database exceptions → domain exceptions.
 * Per TestingStrategy.md: one happy path, key error paths only.
 */
#[CoversClass(SupabaseClient::class)]
final class SupabaseClientTest extends TestCase
{
    private LoggerInterface&MockInterface $mockLogger;

    private DatabaseManager&MockInterface $mockDb;

    private function createClient(): SupabaseClient
    {
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->allows('warning');
        $this->mockLogger->allows('error');

        $this->mockDb = Mockery::mock(DatabaseManager::class);

        return new SupabaseClient($this->mockLogger, $this->mockDb);
    }

    #[Test]
    public function execute_returns_operation_result(): void
    {
        $client = $this->createClient();

        $result = $client->execute(static fn(): array => ['id' => 123, 'status' => 'active']);

        $this->assertSame(['id' => 123, 'status' => 'active'], $result);
    }

    #[Test]
    public function execute_translates_transient_error_to_external_service_unavailable(): void
    {
        $client = $this->createClient();

        $this->expectException(ExternalServiceUnavailableException::class);

        $client->execute(static fn() => throw new LostConnectionException('Connection lost'));
    }

    #[Test]
    public function execute_translates_permanent_error_to_database_operation_failed(): void
    {
        $client = $this->createClient();

        $queryException = new QueryException('pgsql', 'SELECT...', [], new Exception('Syntax error'));

        $this->expectException(DatabaseOperationFailedException::class);

        $client->execute(static fn() => throw $queryException);
    }

    #[Test]
    public function execute_translates_unique_constraint_to_duplicate_record(): void
    {
        $client = $this->createClient();

        $pdoException = new PDOException('duplicate key violates unique constraint "orders_pk" on relation "orders"');
        $exception = new UniqueConstraintViolationException('pgsql', 'INSERT...', [], $pdoException);

        $this->expectException(DuplicateRecordException::class);

        $client->execute(static fn() => throw $exception);
    }

    #[Test]
    public function executeTransaction_wraps_operation_in_database_transaction(): void
    {
        $client = $this->createClient();

        $this->mockDb->shouldReceive('transaction')
            ->once()
            ->withArgs(static fn(Closure $callback, int $attempts): bool => $attempts === 1)
            ->andReturnUsing(static fn(Closure $callback): mixed => $callback());

        $result = $client->executeTransaction(static fn(): array => ['id' => 456]);

        $this->assertSame(['id' => 456], $result);
    }
}
