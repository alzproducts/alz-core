<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Generic Eloquent operations gateway.
 *
 * Provides model-agnostic database operations for use by repositories.
 * All methods accept the model class as a parameter, eliminating the need
 * for per-entity subclasses when only generic operations are needed.
 *
 * This is an Infrastructure-internal service. Application layer should
 * use repository interfaces, not this gateway directly.
 *
 * Error handling is delegated to DatabaseGatewayInterface which translates
 * database exceptions to domain exceptions.
 */
final readonly class EloquentGateway
{
    public function __construct(
        private DatabaseGatewayInterface $dbGateway,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Query Operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if a record exists by column value.
     *
     * @param class-string<Model> $modelClass
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function exists(string $modelClass, string $column, int|string $value): bool
    {
        return $this->dbGateway->query(
            static fn(): bool => $modelClass::query()
                ->where($column, $value)
                ->exists(),
        );
    }

    /**
     * Find a single record by column value.
     *
     * @param class-string<Model> $modelClass
     * @param list<string> $relations Relations to eager load
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function find(
        string $modelClass,
        string $column,
        int|string $value,
        array $relations = [],
    ): ?Model {
        return $this->dbGateway->query(
            static function () use ($modelClass, $column, $value, $relations): ?Model {
                $query = $modelClass::query()->where($column, $value);

                if ($relations !== []) {
                    $query->with($relations);
                }

                return $query->first();
            },
        );
    }

    /**
     * Find a single record by column value or throw.
     *
     * @param class-string<Model> $modelClass
     * @param list<string> $relations Relations to eager load
     *
     * @throws ResourceNotFoundException When record not found
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findOrFail(
        string $modelClass,
        string $column,
        int|string $value,
        array $relations = [],
        string $entityTypeName = 'Record',
    ): Model {
        $model = $this->find($modelClass, $column, $value, $relations);

        if ($model === null) {
            throw new ResourceNotFoundException('Database', $entityTypeName, $value);
        }

        return $model;
    }

    /**
     * Find multiple records by column values.
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $values
     * @param list<string> $relations Relations to eager load
     *
     * @return Collection<int, Model>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findMany(
        string $modelClass,
        string $column,
        array $values,
        array $relations = [],
    ): Collection {
        return $this->dbGateway->query(
            static function () use ($modelClass, $column, $values, $relations): Collection {
                $query = $modelClass::query()->whereIn($column, $values);

                if ($relations !== []) {
                    $query->with($relations);
                }

                return $query->get();
            },
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write Operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Batch upsert with automatic fallback to per-row processing on errors.
     *
     * Primary entry point for bulk upserts. Processes rows in batches, attempting
     * high-performance bulk upsert first. If a batch fails (constraint violation,
     * data issue), automatically falls back to per-row processing for that batch
     * to identify and skip problematic rows while saving the rest.
     *
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows Data rows to upsert (raw attributes)
     * @param list<string> $uniqueBy Columns that determine uniqueness
     * @param list<string>|null $update Columns to update on conflict (null = all except unique columns)
     * @param int $batchSize Number of rows per batch (default 500)
     * @param string $identifierColumn Column to use for identifying failed rows in result
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (bubbles up for retry)
     */
    public function batchUpsertMany(
        string $modelClass,
        array $rows,
        array $uniqueBy,
        ?array $update = null,
        int $batchSize = 500,
        string $identifierColumn = 'external_id',
    ): SaveManyResult {
        if ($rows === []) {
            return new SaveManyResult(succeeded: 0, failed: 0, failedReferences: []);
        }

        $succeeded = 0;
        $failed = 0;
        /** @var list<int|string> $failedReferences */
        $failedReferences = [];

        /** @var positive-int $safeBatchSize */
        $safeBatchSize = \max(1, $batchSize);
        $batches = \array_chunk($rows, $safeBatchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->upsertMany($modelClass, $batch, $uniqueBy, $update);
                $succeeded += \count($batch);
            } catch (ExternalServiceUnavailableException $e) {
                // DB unavailable - bubble up for job retry
                throw $e;
            } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
                // Batch failed - fall back to per-row processing
                Log::warning('Bulk upsert failed, falling back to per-row processing', [
                    'model' => $modelClass,
                    'batch' => $batchIndex + 1,
                    'batch_size' => \count($batch),
                    'error' => $e->getMessage(),
                ]);

                $result = $this->upsertOneByOne(
                    $modelClass,
                    $batch,
                    $uniqueBy,
                    $update,
                    $identifierColumn,
                );

                $succeeded += $result->succeeded;
                $failed += $result->failed;
                $failedReferences = [...$failedReferences, ...$result->failedReferences];
            }
        }

        return new SaveManyResult(
            succeeded: $succeeded,
            failed: $failed,
            failedReferences: $failedReferences,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Upsert Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Bulk upsert records (high-performance).
     *
     * Performs INSERT ... ON DUPLICATE KEY UPDATE (MySQL) or equivalent.
     * Applies model casts before upserting to ensure proper type handling.
     *
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows Data rows to upsert (raw attributes)
     * @param list<string> $uniqueBy Columns that determine uniqueness
     * @param list<string>|null $update Columns to update on conflict (null = all except unique columns)
     *
     * @return int Number of rows affected
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function upsertMany(
        string $modelClass,
        array $rows,
        array $uniqueBy,
        ?array $update = null,
    ): int {
        if ($rows === []) {
            return 0;
        }

        return $this->dbGateway->transact(
            static function () use ($modelClass, $rows, $uniqueBy, $update): int {
                // fillForInsert applies casts, sets UUIDs, and adds timestamps
                $preparedRows = $modelClass::query()->fillForInsert($rows);

                return $modelClass::upsert($preparedRows, $uniqueBy, $update);
            },
        );
    }

    /**
     * Upsert records one by one with per-row error handling.
     *
     * The "boring" but reliable approach: loops through each row, catches
     * errors individually, and continues processing. Failed rows are tracked
     * in the result.
     *
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows Data rows to upsert
     * @param list<string> $uniqueBy Columns that determine uniqueness
     * @param list<string>|null $update Columns to update on conflict
     * @param string $identifierColumn Column to use for identifying failed rows in result
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (bubbles up for retry)
     */
    private function upsertOneByOne(
        string $modelClass,
        array $rows,
        array $uniqueBy,
        ?array $update = null,
        string $identifierColumn = 'external_id',
    ): SaveManyResult {
        $succeeded = 0;
        $failed = 0;
        /** @var list<int|string> $failedReferences */
        $failedReferences = [];

        foreach ($rows as $row) {
            try {
                $this->dbGateway->transact(
                    static function () use ($modelClass, $row, $uniqueBy, $update): int {
                        $preparedRows = $modelClass::query()->fillForInsert([$row]);

                        return $modelClass::upsert($preparedRows, $uniqueBy, $update);
                    },
                );

                $succeeded++;
            } catch (ExternalServiceUnavailableException $e) {
                // Transient failure (DB unavailable) - bubble up for job retry
                throw $e;
            } catch (DuplicateRecordException) {
                // Shouldn't happen with upsert, but count as success defensively
                $succeeded++;
            } catch (DatabaseOperationFailedException) {
                // Permanent failure - track and continue
                $failed++;
                /** @var int|string $identifier */
                $identifier = $row[$identifierColumn] ?? 'unknown';
                $failedReferences[] = $identifier;
            }
        }

        return new SaveManyResult(
            succeeded: $succeeded,
            failed: $failed,
            failedReferences: $failedReferences,
        );
    }

    /**
     * Bulk insert records (no update on conflict).
     *
     * Use when you know records don't exist. Faster than upsert but will
     * throw DuplicateRecordException on conflicts.
     *
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows Data rows to insert
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException When unique constraint violated
     * @throws ExternalServiceUnavailableException
     */
    public function insertMany(string $modelClass, array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        return $this->dbGateway->transact(
            static function () use ($modelClass, $rows): bool {
                // fillForInsert applies casts, sets UUIDs, and adds timestamps
                $preparedRows = $modelClass::query()->fillForInsert($rows);

                return $modelClass::insert($preparedRows);
            },
        );
    }

    /**
     * Update records matching column value.
     *
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $data Data to update
     *
     * @return int Number of rows affected
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function updateWhere(
        string $modelClass,
        string $column,
        int|string $value,
        array $data,
    ): int {
        return $this->dbGateway->transact(
            static fn(): int => $modelClass::query()
                ->where($column, $value)
                ->update($data),
        );
    }

    /**
     * Delete records matching column value.
     *
     * @param class-string<Model> $modelClass
     *
     * @return int Number of rows deleted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteWhere(string $modelClass, string $column, int|string $value): int
    {
        return $this->dbGateway->transact(
            /**
             * @return int
             */
            static function () use ($modelClass, $column, $value): int {
                /** @var int */
                return $modelClass::query()
                    ->where($column, $value)
                    ->delete();
            },
        );
    }
}
