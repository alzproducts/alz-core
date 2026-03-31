<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\Shared\Pagination\ValueObjects\PagedList;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

    /**
     * Paginate records with query constraints, eager loading, and domain mapping.
     *
     * Builds a paginated query, maps each model through the provided closure,
     * and returns a framework-free PagedList. Reusable across repositories.
     *
     * @template TModel of Model
     * @template TResult
     *
     * @param class-string<TModel> $modelClass
     * @param Closure(Builder<covariant Model>): void $scope Query constraints (where clauses, ordering)
     * @param list<string> $relations Relations to eager load
     * @param-immediately-invoked-callable $mapper
     * @param Closure(TModel): TResult $mapper Transform each model to a domain object
     *
     * @return PagedList<TResult>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(
        string $modelClass,
        Closure $scope,
        array $relations,
        Closure $mapper,
        int $perPage,
        int $page,
    ): PagedList {
        /** @var PagedList<TResult> */
        return $this->dbGateway->query(static function () use ($modelClass, $scope, $relations, $mapper, $perPage, $page): PagedList {
            $query = $modelClass::query();

            $scope($query);

            if ($relations !== []) {
                $query->with($relations);
            }

            $paginator = $query->paginate(perPage: $perPage, page: $page);

            /** @var list<TResult> $items */
            $items = $paginator->getCollection()
                ->map(static function (Model $model) use ($mapper): mixed {
                    /** @var TModel $model */
                    return $mapper($model);
                })
                ->all();

            return PagedList::fromPage(
                items: $items,
                total: $paginator->total(),
                perPage: $paginator->perPage(),
                currentPage: $paginator->currentPage(),
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write Operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute safe update columns for upsert operations.
     *
     * Always excludes 'id' (primary keys must never change - child FKs reference them)
     * and the uniqueBy columns (conflict detection columns shouldn't be updated).
     *
     * @param array<string, mixed> $preparedRow A single prepared row to extract column names from
     * @param list<string> $uniqueBy Columns used for conflict detection
     * @param list<string>|null $explicitUpdate Caller-specified update columns (null = compute default)
     *
     * @return list<string> Safe columns to update on conflict
     */
    private static function computeUpdateColumns(
        array $preparedRow,
        array $uniqueBy,
        ?array $explicitUpdate,
    ): array {
        // If caller specified columns, filter out 'id' for safety
        if ($explicitUpdate !== null) {
            return \array_values(\array_filter(
                $explicitUpdate,
                static fn(string $col): bool => $col !== 'id',
            ));
        }

        // Default: all columns except 'id' and uniqueBy columns
        return \array_values(\array_filter(
            \array_keys($preparedRow),
            static fn(string $col): bool => $col !== 'id' && !\in_array($col, $uniqueBy, true),
        ));
    }

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
                $updateColumns = self::computeUpdateColumns($preparedRows[0] ?? [], $uniqueBy, null);

                return $modelClass::upsert($preparedRows, $uniqueBy, $updateColumns);
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

    /**
     * Bulk upsert records (high-performance).
     *
     * Performs INSERT ... ON CONFLICT DO UPDATE (PostgreSQL) or equivalent.
     * Applies model casts before upserting to ensure proper type handling.
     *
     * Use this when you need a simple bulk upsert without fallback logic.
     * For large batches with automatic chunking and per-row fallback on errors,
     * use batchUpsertMany() instead.
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
    public function upsertMany(
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
                $updateColumns = self::computeUpdateColumns($preparedRows[0] ?? [], $uniqueBy, $update);

                return $modelClass::upsert($preparedRows, $uniqueBy, $updateColumns);
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
                        $updateColumns = self::computeUpdateColumns($preparedRows[0] ?? [], $uniqueBy, $update);

                        return $modelClass::upsert($preparedRows, $uniqueBy, $updateColumns);
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
     * Insert a single record and return the generated ID.
     *
     * Uses fillForInsert() to apply casts, generate UUID, and add timestamps.
     * Returns the generated ID (typically UUID for models with HasUuids trait).
     *
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $attributes Data to insert
     *
     * @return string The generated primary key (UUID)
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException When unique constraint violated
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException If fillForInsert returns unexpected result (programming error)
     */
    public function insertOne(string $modelClass, array $attributes): string
    {
        return $this->dbGateway->transact(
            static function () use ($modelClass, $attributes): string {
                // fillForInsert applies casts, sets UUIDs, and adds timestamps
                /** @var list<array<string, mixed>> $preparedRows */
                $preparedRows = $modelClass::query()->fillForInsert([$attributes]);

                // Extract the single prepared row (fillForInsert always returns same count as input)
                $preparedRow = \array_shift($preparedRows);

                if ($preparedRow === null) {
                    throw new RuntimeException('fillForInsert returned empty array');
                }

                if (!isset($preparedRow['id']) || !\is_string($preparedRow['id'])) {
                    throw new RuntimeException('Prepared row missing string id key');
                }

                $modelClass::insert([$preparedRow]);

                return $preparedRow['id'];
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

    /**
     * Delete records matching a column value but NOT in a list of values.
     *
     * Useful for syncing child records: delete rows that exist in the database
     * but are no longer present in the incoming data.
     *
     * Example: Delete order products where order_external_id = 123
     *          but external_id NOT IN [1, 2, 3] (the current product IDs)
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $notInValues Values to exclude from deletion
     *
     * @return int Number of rows deleted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteWhereNotIn(
        string $modelClass,
        string $whereColumn,
        int|string $whereValue,
        string $notInColumn,
        array $notInValues,
    ): int {
        $modelName = \class_basename($modelClass);

        $deleted = $this->dbGateway->transact(
            static function () use ($modelClass, $whereColumn, $whereValue, $notInColumn, $notInValues): int {
                $query = $modelClass::query()->where($whereColumn, $whereValue);

                // Only add whereNotIn if there are values to exclude
                if ($notInValues !== []) {
                    $query->whereNotIn($notInColumn, $notInValues);
                }

                /** @var int */
                return $query->delete();
            },
        );

        Log::debug('Conditional delete completed', [
            'model' => $modelName,
            'where' => [$whereColumn => $whereValue],
            'not_in' => $notInColumn,
            'excluded_count' => \count($notInValues),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Delete rows matching whereIn AND not matching notIn.
     *
     * Useful for bulk orphan-delete across multiple parents in a single query.
     * Example: delete items for PO IDs [A, B, C] where item ID NOT IN [1, 2, 3]
     *
     * If $notInValues is empty, skips the whereNotIn clause — deletes all rows
     * matching the whereIn (handles parents with zero children).
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $whereInValues Parent IDs to scope deletion
     * @param list<int|string> $notInValues Child IDs to keep (exclude from deletion)
     *
     * @return int Number of rows deleted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteWhereInAndNotIn(
        string $modelClass,
        string $whereInColumn,
        array $whereInValues,
        string $notInColumn,
        array $notInValues,
    ): int {
        if ($whereInValues === []) {
            return 0;
        }

        $modelName = \class_basename($modelClass);

        $deleted = $this->dbGateway->transact(
            static function () use ($modelClass, $whereInColumn, $whereInValues, $notInColumn, $notInValues): int {
                $query = $modelClass::query()->whereIn($whereInColumn, $whereInValues);

                if ($notInValues !== []) {
                    $query->whereNotIn($notInColumn, $notInValues);
                }

                /** @var int */
                return $query->delete();
            },
        );

        Log::debug('Bulk orphan delete completed', [
            'model' => $modelName,
            'where_in' => $whereInColumn,
            'parent_count' => \count($whereInValues),
            'not_in' => $notInColumn,
            'excluded_count' => \count($notInValues),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Delete records whose column value is NOT in the provided list.
     *
     * Used for full-replace reconciliation: after upserting all records from
     * an external source, delete any local records not present in the source.
     *
     * Safety: returns 0 immediately if $idsToKeep is empty — never deletes
     * all records without an explicit scope.
     *
     * @param class-string<Model> $modelClass
     * @param list<int|string> $idsToKeep Values to exclude from deletion
     *
     * @return int Number of rows deleted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function reconcileWhereNotIn(string $modelClass, string $column, array $idsToKeep): int
    {
        if ($idsToKeep === []) {
            return 0;
        }

        $modelName = \class_basename($modelClass);

        $deleted = $this->dbGateway->transact(
            static function () use ($modelClass, $column, $idsToKeep): int {
                /** @var int */
                return $modelClass::query()
                    ->whereNotIn($column, $idsToKeep)
                    ->delete();
            },
        );

        Log::debug('Reconcile delete completed', [
            'model' => $modelName,
            'column' => $column,
            'excluded_count' => \count($idsToKeep),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }
}
