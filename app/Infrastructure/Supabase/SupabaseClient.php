<?php

declare(strict_types=1);

namespace App\Infrastructure\Supabase;

use App\Application\Contracts\DatabaseClientInterface;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Supabase database client with exception translation.
 *
 * Translates Laravel/PDO exceptions to domain exceptions, enabling
 * callers to distinguish transient errors (retry) from permanent ones (fail).
 *
 * PostgreSQL error codes reference:
 * - 08xxx: Connection exceptions (transient)
 * - 23505: Unique violation (permanent)
 * - 40001, 40P01: Deadlock/serialization (transient)
 * - 57014: Query cancelled/timeout (transient)
 * - 57Pxx: Admin shutdown (transient)
 */
final readonly class SupabaseClient implements DatabaseClientInterface
{
    private const string SERVICE_NAME = 'Supabase';

    /**
     * PostgreSQL error codes that indicate transient failures.
     *
     * @var list<string>
     */
    private const array TRANSIENT_ERROR_CODES = [
        '40001', '40P01',              // Deadlock/serialization failure
        '57014',                        // Query cancelled (timeout)
        '57P01', '57P02', '57P03',      // Admin shutdown
        '08000', '08003', '08006',      // Connection errors
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @phpstan-return T
     */
    public function execute(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->handleUniqueConstraint($e);
        } catch (LostConnectionException $e) {
            throw $this->handleLostConnection($e);
        } catch (DeadlockException $e) {
            throw $this->handleDeadlock($e);
        } catch (QueryException $e) {
            throw $this->handleQueryException($e);
        } catch (PDOException $e) {
            throw $this->handlePdoException($e);
        }
    }

    private function handleUniqueConstraint(UniqueConstraintViolationException $e): DuplicateRecordException
    {
        $this->logger->warning('Unique constraint violation', [
            'service' => self::SERVICE_NAME,
            'sql' => $e->getSql(),
            'code' => $e->getCode(),
        ]);

        // Extract table and constraint from error message
        $table = self::extractTableName($e->getMessage());
        $constraint = self::extractConstraintName($e->getMessage());

        return new DuplicateRecordException($table, $constraint, $e);
    }

    private function handleLostConnection(LostConnectionException $e): ExternalServiceUnavailableException
    {
        $this->logger->error('Database connection lost', [
            'service' => self::SERVICE_NAME,
            'message' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, retryAfter: 30, previous: $e);
    }

    private function handleDeadlock(DeadlockException $e): ExternalServiceUnavailableException
    {
        $this->logger->warning('Database deadlock detected', [
            'service' => self::SERVICE_NAME,
            'message' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, retryAfter: 5, previous: $e);
    }

    private function handleQueryException(QueryException $e): DatabaseOperationFailedException|ExternalServiceUnavailableException
    {
        $sqlState = $this->extractSqlState($e);

        $this->logger->error('Database query failed', [
            'service' => self::SERVICE_NAME,
            'sql' => $e->getSql(),
            'code' => $e->getCode(),
            'sqlstate' => $sqlState,
        ]);

        // Check if this is a transient error based on SQLSTATE
        if (($sqlState !== null) && self::isTransientError($sqlState)) {
            return new ExternalServiceUnavailableException(self::SERVICE_NAME, retryAfter: 10, previous: $e);
        }

        return new DatabaseOperationFailedException(
            operation: 'query',
            reason: $e->getMessage(),
            previous: $e,
        );
    }

    private function handlePdoException(PDOException $e): ExternalServiceUnavailableException
    {
        $this->logger->error('PDO exception occurred', [
            'service' => self::SERVICE_NAME,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);

        // PDO exceptions at this level are typically connection issues - treat as transient
        return new ExternalServiceUnavailableException(self::SERVICE_NAME, retryAfter: 30, previous: $e);
    }

    private function extractSqlState(QueryException $e): ?string
    {
        $previous = $e->getPrevious();

        if (($previous instanceof PDOException) && \is_string($previous->getCode())) {
            return $previous->getCode();
        }

        return null;
    }

    private static function isTransientError(string $sqlState): bool
    {
        // Check exact matches
        if (\in_array($sqlState, self::TRANSIENT_ERROR_CODES, true)) {
            return true;
        }

        // Check connection error class (08xxx)
        return \str_starts_with($sqlState, '08');
    }

    private static function extractTableName(string $message): string
    {
        // PostgreSQL format: ... relation "table_name" ...
        if (\preg_match('/relation "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        // PostgreSQL format: ... table "schema.table_name" ...
        if (\preg_match('/table "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }

    private static function extractConstraintName(string $message): string
    {
        // PostgreSQL format: ... constraint "constraint_name" ...
        if (\preg_match('/constraint "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        // PostgreSQL format: ... unique constraint "constraint_name" ...
        if (\preg_match('/unique constraint "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }
}
