<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\RepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Abstract base class for Eloquent repositories.
 *
 * Provides shared implementations for common operations:
 * - saveMany(): Batch saves with continue-on-failure semantics
 * - saveManyBulk(): High-performance bulk upsert for large syncs (Phase 2)
 * - getByColumn(): Generic query by column with domain mapping
 * - existsByColumn(): Existence check by column
 *
 * Vendor-specific abstracts (Linnworks, ShopWired) extend this class and narrow
 * the identifier type (string GUID vs int external ID) via abstract methods.
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

    /**
     * Get the human-readable entity type name for logging.
     *
     * Example: 'Order', 'Customer', 'StockItem'
     */
    abstract protected function getEntityTypeName(): string;

    /**
     * Get the log key name for the entity's external identifier.
     *
     * Used to create consistent log context across vendor systems.
     * Example: 'external_id' for ShopWired, 'linnworks_id' for Linnworks.
     */
    abstract protected function getIdentifierLogKey(): string;

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
                    $this->getIdentifierLogKey() => $this->getEntityIdentifier($entity),
                ]);
            } catch (DatabaseOperationFailedException $e) {
                // Permanent failure - log and continue batch
                $failed++;
                $identifier = $this->getEntityIdentifier($entity);
                $failedReferences[] = $identifier;

                Log::error($this->getLogMessageForFailedSave(), [
                    $this->getIdentifierLogKey() => $identifier,
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

    // ─────────────────────────────────────────────────────────────────────────
    // Query Operations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @return T
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException When model doesn't implement EloquentDomainMappableInterface (programming error)
     */
    public function getByColumn(int|string $value, string $column): object
    {
        return $this->gateway->query(function () use ($value, $column): object {
            $modelClass = $this->getModelClass();

            $query = $modelClass::query()->where($column, $value);

            $relations = $this->getEagerLoadRelations();
            if ($relations !== []) {
                $query->with($relations);
            }

            /** @var Model|null $model */
            $model = $query->first();

            if ($model === null) {
                throw new ResourceNotFoundException(
                    'Database',
                    $this->getEntityTypeName(),
                    $value,
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
    public function existsByColumn(int|string $value, string $column): bool
    {
        $modelClass = $this->getModelClass();

        return $this->gateway->query(
            // @phpstan-ignore staticMethod.dynamicCall (class-string used dynamically for Eloquent query builder)
            static fn(): bool => $modelClass::query()
                ->where($column, $value)
                ->exists(),
        );
    }
}
