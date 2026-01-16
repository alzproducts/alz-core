<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\ShopwiredRepositoryInterface;
use App\Application\ValueObjects\SaveManyResult;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for ShopWired Eloquent repositories.
 *
 * Provides shared implementations for common operations:
 * - saveMany(): Batch saves with continue-on-failure semantics
 * - existsByExternalId(): Existence check by ShopWired's external ID
 * - getByExternalId(): Fetch and map to domain by external ID
 *
 * Concrete repositories implement entity-specific logic via abstract methods.
 *
 * @template T of object
 *
 * @implements ShopwiredRepositoryInterface<T>
 */
abstract class AbstractShopwiredEloquentRepository implements ShopwiredRepositoryInterface
{
    public function __construct(
        protected readonly DatabaseGatewayInterface $gateway,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Shared Implementations
    // ─────────────────────────────────────────────────────────────────────────

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
                // Entity already exists (from previous sync or concurrent process)
                // Not a failure - the entity exists, which is the goal
                $succeeded++;
                Log::info("{$this->getEntityTypeName()} already exists, skipping duplicate", [
                    'external_id' => $this->getEntityIdentifier($entity),
                ]);
            } catch (DatabaseOperationFailedException $e) {
                // Permanent failure - log and continue batch
                $failed++;
                $identifier = $this->getEntityIdentifier($entity);
                $failedReferences[] = $identifier;

                Log::error($this->getLogMessageForFailedSave(), [
                    'external_id' => $identifier,
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
     * {@inheritDoc}
     *
     * @return T
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getByExternalId(int $externalId): object
    {
        return $this->gateway->query(function () use ($externalId): object {
            $modelClass = $this->getModelClass();

            /** @var Model|null $model */
            $model = $modelClass::query()
                ->where('external_id', $externalId)
                ->with($this->getEagerLoadRelations())
                ->first();

            if ($model === null) {
                throw new ResourceNotFoundException(
                    'Database',
                    $this->getEntityTypeName(),
                    $externalId,
                );
            }

            return $this->mapModelToDomain($model);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function existsByExternalId(int $externalId): bool
    {
        $modelClass = $this->getModelClass();

        return $this->gateway->query(
            static fn(): bool => $modelClass::query()
                ->where('external_id', $externalId)
                ->exists(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Methods - Implement in Concrete Repositories
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the Eloquent model class for this repository.
     *
     * @return class-string<Model>
     */
    abstract protected function getModelClass(): string;

    /**
     * Get relation names to eager load when fetching entities.
     *
     * @return list<string>
     */
    abstract protected function getEagerLoadRelations(): array;

    /**
     * Extract the ShopWired external ID from a domain entity.
     *
     * Used for logging failed saves in saveMany().
     */
    abstract protected function getEntityIdentifier(object $entity): int;

    /**
     * Get the human-readable entity type name for logging.
     *
     * Example: 'Order', 'Customer', 'Product'
     */
    abstract protected function getEntityTypeName(): string;

    /**
     * Map an Eloquent model (with loaded relations) to a domain entity.
     *
     * @return T
     */
    abstract protected function mapModelToDomain(Model $model): object;

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
