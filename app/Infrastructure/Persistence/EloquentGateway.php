<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Closure;
use Generator;
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
     * Execute a read query with domain exception translation.
     *
     * Use for custom queries that don't fit the generic methods below.
     * Catches database exceptions and translates to domain exceptions.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $callback
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function query(Closure $callback): mixed
    {
        return $this->dbGateway->query($callback);
    }

    /**
     * Execute operations within a database transaction.
     *
     * Wraps the callback in a transaction with automatic retry support.
     * Use for operations that modify multiple tables atomically.
     *
     * @template T
     *
     * @param-immediately-invoked-callable $callback
     * @param Closure(): T $callback
     * @param int $attempts Number of retry attempts on deadlock (default 1 = no retry)
     *
     * @return T
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function transact(Closure $callback, int $attempts = 1): mixed
    {
        return $this->dbGateway->transact($callback, $attempts);
    }

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
     * @template TModel of Model
     *
     * @param class-string<TModel> $modelClass
     * @param list<string> $relations Relations to eager load
     *
     * @return TModel|null
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function find(
        string $modelClass,
        string $column,
        int|string $value,
        array $relations = [],
    ): ?Model {
        /** @var TModel|null */
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
     * When a mapper is provided, applies it to the found model and returns the result.
     * Without a mapper, returns the raw Eloquent model.
     *
     * @template TModel of Model
     * @template TResult
     *
     * @param class-string<TModel> $modelClass
     * @param list<string> $relations Relations to eager load
     * @param-immediately-invoked-callable $mapper
     * @param (Closure(TModel): TResult)|null $mapper Optional mapper to transform the model
     *
     * @return ($mapper is null ? TModel : TResult)
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
        ?Closure $mapper = null,
    ): mixed {
        $model = $this->find($modelClass, $column, $value, $relations);

        if ($model === null) {
            throw new ResourceNotFoundException('Database', $entityTypeName, $value);
        }

        return $mapper !== null ? $mapper($model) : $model;
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

    /**
     * Stream all records with lazy loading for memory efficiency.
     *
     * Uses Laravel's lazy() to process records in chunks without loading
     * all records into memory at once. Ideal for large datasets.
     *
     * When a mapper is provided, applies it to each model before yielding.
     * Without a mapper, yields raw Eloquent models.
     *
     * @template TModel of Model
     * @template TResult
     *
     * @param class-string<TModel> $modelClass
     * @param list<string> $relations Relations to eager load
     * @param-immediately-invoked-callable $mapper
     * @param (Closure(TModel): TResult)|null $mapper Optional mapper to transform each model
     * @param positive-int $chunkSize Number of records per chunk (default 100)
     *
     * @return Generator<int, ($mapper is null ? TModel : TResult)>
     */
    public function streamAll(
        string $modelClass,
        array $relations = [],
        ?Closure $mapper = null,
        int $chunkSize = 100,
    ): Generator {
        $query = $modelClass::query();

        if ($relations !== []) {
            $query->with($relations);
        }

        $lazyCollection = $query->lazy($chunkSize);

        foreach ($lazyCollection as $model) {
            /** @var TModel $model */
            yield $mapper !== null ? $mapper($model) : $model;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write Operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a single record with transaction and error handling.
     *
     * Uses fillForInsert() + upsert() for consistency with bulk operations.
     * Applies casts, generates UUIDs, and adds timestamps automatically.
     *
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $attributes Data to insert/update (must include unique key values)
     * @param list<string> $uniqueBy Column names that determine uniqueness (default: ['external_id'])
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertOne(
        string $modelClass,
        array $attributes,
        array $uniqueBy,
    ): void {
        $this->dbGateway->transact(
            static function () use ($modelClass, $attributes, $uniqueBy): int {
                $preparedRows = $modelClass::query()->fillForInsert([$attributes]);

                return $modelClass::upsert($preparedRows, $uniqueBy);
            },
        );
    }

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

        $modelName = \class_basename($modelClass);
        /** @var positive-int $safeBatchSize */
        $safeBatchSize = \max(1, $batchSize);
        $batches = \array_chunk($rows, $safeBatchSize);

        Log::debug('Starting batch upsert', [
            'model' => $modelName,
            'total_rows' => \count($rows),
            'batch_size' => $safeBatchSize,
            'total_batches' => \count($batches),
        ]);

        $succeeded = 0;
        $failed = 0;
        /** @var list<int|string> $failedReferences */
        $failedReferences = [];

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
                    'model' => $modelName,
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

        Log::info('Batch upsert completed', [
            'model' => $modelName,
            'total_rows' => \count($rows),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);

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
        $modelName = \class_basename($modelClass);
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
            } catch (DatabaseOperationFailedException $e) {
                // Permanent failure - track and continue
                $failed++;
                /** @var int|string $identifier */
                $identifier = $row[$identifierColumn] ?? 'unknown';
                $failedReferences[] = $identifier;

                Log::error('Per-row upsert failed', [
                    'model' => $modelName,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failed > 0) {
            Log::warning('Per-row fallback completed with failures', [
                'model' => $modelName,
                'total' => \count($rows),
                'succeeded' => $succeeded,
                'failed' => $failed,
            ]);
        } else {
            Log::debug('Per-row fallback completed successfully', [
                'model' => $modelName,
                'succeeded' => $succeeded,
            ]);
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

        $modelName = \class_basename($modelClass);
        $count = \count($rows);

        $result = $this->dbGateway->transact(
            static function () use ($modelClass, $rows): bool {
                // fillForInsert applies casts, sets UUIDs, and adds timestamps
                $preparedRows = $modelClass::query()->fillForInsert($rows);

                return $modelClass::insert($preparedRows);
            },
        );

        Log::debug('Bulk insert completed', [
            'model' => $modelName,
            'count' => $count,
        ]);

        return $result;
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
        $modelName = \class_basename($modelClass);

        $affected = $this->dbGateway->transact(
            static fn(): int => $modelClass::query()
                ->where($column, $value)
                ->update($data),
        );

        Log::debug('Update completed', [
            'model' => $modelName,
            'column' => $column,
            'value' => $value,
            'affected' => $affected,
        ]);

        return $affected;
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
        $modelName = \class_basename($modelClass);

        $deleted = $this->dbGateway->transact(
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

        Log::debug('Delete completed', [
            'model' => $modelName,
            'column' => $column,
            'value' => $value,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Delete records matching multiple column values.
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $values
     *
     * @return int Number of rows deleted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteWhereIn(string $modelClass, string $column, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $modelName = \class_basename($modelClass);

        $deleted = $this->dbGateway->transact(
            static function () use ($modelClass, $column, $values): int {
                /** @var int */
                return $modelClass::query()
                    ->whereIn($column, $values)
                    ->delete();
            },
        );

        Log::debug('Bulk delete completed', [
            'model' => $modelName,
            'column' => $column,
            'count' => \count($values),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }
}
