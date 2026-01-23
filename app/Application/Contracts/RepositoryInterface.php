<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Base repository interface for entity persistence.
 *
 * Defines common operations shared across all repository implementations.
 * Vendor-specific interfaces (Linnworks, ShopWired) extend this and add
 * their own query methods as needed.
 *
 * @template T of object
 */
interface RepositoryInterface
{
    /**
     * Persist an entity (upsert based on external identifier).
     *
     * @param T $entity
     *
     * @throws DatabaseOperationFailedException On constraint violations or schema errors
     * @throws DuplicateRecordException When unique constraint violated (shouldn't happen with upsert)
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (retry later)
     */
    public function save(object $entity): void;

    /**
     * Persist multiple entities, continuing on individual failures.
     *
     * Individual save failures are logged and counted; processing continues.
     * Only throws if the database becomes completely unavailable mid-batch.
     *
     * @param list<T> $entities
     *
     * @return SaveManyResult Results with succeeded/failed counts and failed identifiers
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (bubbled for job retry)
     */
    public function saveMany(array $entities): SaveManyResult;

    /**
     * Get entity by a specific column value.
     *
     * Generic query method that allows fetching by any indexed column.
     * Returns the mapped Domain object.
     *
     * @param int|string $value The value to search for
     * @param string $column The column name to search in
     *
     * @return T The domain entity
     *
     * @throws ResourceNotFoundException When entity not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException When duplicate unexpectedly found (defensive)
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getByColumn(int|string $value, string $column): object;

    /**
     * Check existence by a specific column value.
     *
     * @param int|string $value The value to search for
     * @param string $column The column name to search in
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException When duplicate unexpectedly found (defensive)
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function existsByColumn(int|string $value, string $column): bool;
}
