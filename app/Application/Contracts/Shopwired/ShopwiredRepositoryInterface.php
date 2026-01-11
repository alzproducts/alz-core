<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\ValueObjects\SaveManyResult;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;

/**
 * Base repository interface for ShopWired entity persistence.
 *
 * Provides common operations for syncing ShopWired entities to local database.
 * ShopWired remains the source of truth; local storage provides fast queries
 * and offline resilience.
 *
 * Entity-specific repositories should extend this interface and add
 * custom query methods (e.g., getByReference, getByEmail).
 *
 * @template T of object
 */
interface ShopwiredRepositoryInterface
{
    /**
     * Persist an entity (upsert based on ShopWired's external ID).
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
     * @return SaveManyResult Results with succeeded/failed counts and failed identifiers
     */
    public function saveMany(array $entities): SaveManyResult;

    /**
     * Get entity by ShopWired's external ID.
     *
     * @return T
     *
     * @throws ResourceNotFoundException When entity not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getByExternalId(int $externalId): object;

    /**
     * Check existence by ShopWired's external ID without exception.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function existsByExternalId(int $externalId): bool;
}
