<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Linnworks\LinnworksRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for Linnworks Eloquent repositories.
 *
 * Provides shared implementations for common operations:
 * - saveMany(): Batch saves with continue-on-failure semantics
 *
 * Concrete repositories implement entity-specific logic:
 * - save(): From LinnworksRepositoryInterface (entity-specific persistence)
 * - getEntityIdentifier(): For logging
 * - getEntityTypeName(): For logging
 *
 * Key difference from ShopWired: Linnworks uses string GUIDs as identifiers.
 *
 * @template T of object
 *
 * @implements LinnworksRepositoryInterface<T>
 */
abstract class AbstractLinnworksEloquentRepository implements LinnworksRepositoryInterface
{
    public function __construct(
        protected readonly DatabaseGatewayInterface $gateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable (bubbled for job retry)
     */
    public function saveMany(array $entities): SaveManyResult
    {
        $succeeded = 0;
        $failed = 0;
        $failedReferences = [];

        foreach ($entities as $entity) {
            try {
                $this->save($entity);
                $succeeded++;
            } catch (ExternalServiceUnavailableException $e) {
                // Transient failure (DB unavailable) - bubble up for job retry
                throw $e;
            } catch (DuplicateRecordException) {
                // Entity already exists (shouldn't happen with upsert, but defensive)
                $succeeded++;
                Log::info("{$this->getEntityTypeName()} already exists, counted as success", [
                    'linnworks_id' => $this->getEntityIdentifier($entity),
                ]);
            } catch (DatabaseOperationFailedException $e) {
                // Permanent failure - log and continue batch
                $failed++;
                $identifier = $this->getEntityIdentifier($entity);
                $failedReferences[] = $identifier;

                Log::error($this->getLogMessageForFailedSave(), [
                    'linnworks_id' => $identifier,
                    'entity_type' => $this->getEntityTypeName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new SaveManyResult(
            succeeded: $succeeded,
            failed: $failed,
            failedReferences: $failedReferences,
        );
    }

    /**
     * Extract the Linnworks GUID identifier from a domain entity.
     *
     * Used for logging failed saves in saveMany().
     */
    abstract protected function getEntityIdentifier(object $entity): string;

    /**
     * Get the human-readable entity type name for logging.
     *
     * Example: 'StockItem', 'Order'
     */
    abstract protected function getEntityTypeName(): string;

    /**
     * Get log message for failed save operations.
     *
     * Override to customize the log message format.
     */
    protected function getLogMessageForFailedSave(): string
    {
        return "Failed to save {$this->getEntityTypeName()}";
    }
}
