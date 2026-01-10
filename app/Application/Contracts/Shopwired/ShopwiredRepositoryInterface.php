<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\ValueObjects\SaveManyResult;
use App\Domain\Exceptions\DatabaseOperationFailedException;
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
     */
    public function save(object $entity): void;

    /**
     * Persist multiple entities, continuing on individual failures.
     *
     * @param list<T> $entities
     * @return SaveManyResult Results with succeeded/failed counts
     *
     * @throws DatabaseOperationFailedException When database is completely unavailable
     */
    public function saveMany(array $entities): SaveManyResult;

    /**
     * Get entity by ShopWired's external ID.
     *
     * @return T
     *
     * @throws ResourceNotFoundException When entity not found
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByExternalId(int $externalId): object;

    /**
     * Check existence by ShopWired's external ID without exception.
     *
     * @throws DatabaseOperationFailedException On query failure
     */
    public function existsByExternalId(int $externalId): bool;
}
