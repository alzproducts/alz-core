<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\ValueObjects\SaveManyResult;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

/**
 * Base repository interface for Linnworks entity persistence.
 *
 * Provides common operations for syncing Linnworks entities to local database.
 * Linnworks remains the source of truth; local storage provides fast queries
 * and offline resilience.
 *
 * Entity-specific repositories should extend this interface and add
 * custom query methods as needed.
 *
 * Key difference from ShopWired: Linnworks uses string GUIDs as identifiers,
 * not integer external IDs.
 *
 * @template T of object
 */
interface LinnworksRepositoryInterface
{
    /**
     * Persist an entity (upsert based on Linnworks GUID).
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
}
