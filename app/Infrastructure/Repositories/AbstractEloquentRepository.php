<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\RepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Abstract base class for Eloquent repositories.
 *
 * Provides shared implementation for batch save operations:
 * - saveMany(): Iterative saves with continue-on-failure semantics
 *
 * Concrete repositories extend this and implement:
 * - save(): Entity-specific upsert logic
 * - Entity-specific query methods (getByEmail, getByReference, etc.)
 *
 * Domain mapping: Override mapModelToDomain() for custom mapping logic.
 * Default implementation calls $model->toDomain() (requires EloquentDomainMappableInterface).
 *
 * @template T of object
 *
 * @implements RepositoryInterface<T>
 */
abstract class AbstractEloquentRepository implements RepositoryInterface
{
    public function __construct(
        protected readonly DatabaseGatewayInterface $gateway,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Required Abstract Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the Eloquent model class for this repository.
     *
     * @return class-string<Model>
     */
    abstract protected function getModelClass(): string;

    /**
     * Extract the external identifier from a domain entity.
     *
     * Used for logging failed saves in saveMany()/saveManyBulk().
     * Return type is int|string to support both ShopWired (int) and Linnworks (string GUID).
     *
     * @param T $entity
     */
    abstract protected function getEntityIdentifier(object $entity): int|string;

    // ─────────────────────────────────────────────────────────────────────────
    // Default Implementations (Override as needed)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the human-readable entity type name for logging.
     *
     * Default derives from model class: OrderModel → 'Order'.
     * Override if the derived name isn't appropriate.
     */
    protected function getEntityTypeName(): string
    {
        $baseName = \class_basename($this->getModelClass());

        return \str_ends_with($baseName, 'Model')
            ? \mb_substr($baseName, 0, -5)
            : $baseName;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Optional Methods (Override as needed)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get relation names to eager load when fetching entities.
     *
     * Override this in concrete repositories to eager load relations.
     *
     * @return list<string>
     */
    protected function getEagerLoadRelations(): array
    {
        return [];
    }

    /**
     * Get log message for failed save operations.
     *
     * Override to customize the log message format.
     */
    protected function getLogMessageForFailedSave(): string
    {
        return "Failed to save {$this->getEntityTypeName()}";
    }

    /**
     * Map an Eloquent model to a domain entity.
     *
     * Default implementation requires models to implement EloquentDomainMappableInterface.
     * Override this method for models that use external mappers (e.g., ProductModel).
     *
     * @param Model $model
     *
     * @return T
     *
     * @throws RuntimeException When model doesn't implement EloquentDomainMappableInterface
     */
    protected function mapModelToDomain(Model $model): object
    {
        if (! $model instanceof EloquentDomainMappableInterface) {
            throw new RuntimeException(\sprintf(
                '%s must implement EloquentDomainMappableInterface, or %s must override mapModelToDomain()',
                $model::class,
                static::class, // @phpstan-ignore symplify.forbiddenStaticClassConstFetch (class name for error message)
            ));
        }

        /** @var T */
        return $model->toDomain();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Batch Save Operations
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
                // Entity already exists (shouldn't happen with upsert, but defensive)
                $succeeded++;
                Log::info("{$this->getEntityTypeName()} already exists, counted as success", [
                    'identifier' => $this->getEntityIdentifier($entity),
                ]);
            } catch (DatabaseOperationFailedException $e) {
                // Permanent failure - log and continue batch
                $failed++;
                $identifier = $this->getEntityIdentifier($entity);
                $failedReferences[] = $identifier;

                Log::error($this->getLogMessageForFailedSave(), [
                    'identifier' => $identifier,
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
}
