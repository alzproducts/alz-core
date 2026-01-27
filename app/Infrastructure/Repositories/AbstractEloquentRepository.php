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
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Abstract base class for Eloquent repositories.
 *
 * Provides shared implementation for batch save operations:
 * - saveMany(): Iterative saves with continue-on-failure semantics (supports relations)
 * - saveManyBulk(): High-performance bulk upsert for flat entities (no relations)
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
        protected readonly EloquentGateway $eloquentGateway,
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
     * Bulk upsert entities using high-performance batch operations.
     *
     * Use this for flat entities WITHOUT relations. For entities with child
     * relations, use saveMany() which calls save() iteratively.
     *
     * The callable must return a complete attribute array including the upsert key.
     * Example: fn(Customer $c) => ['external_id' => $c->id, ...toModelAttributes($c)]
     *
     * @param list<T> $entities Entities to persist
     * @param callable(T): array<string, mixed> $entityToAttributes Maps entity to model attributes
     * @param list<string> $upsertKeys Columns that determine uniqueness (default: ['external_id'])
     * @param int $batchSize Rows per batch (default: 500)
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveManyBulk(
        array $entities,
        callable $entityToAttributes,
        array $upsertKeys = ['external_id'],
        int $batchSize = 500,
    ): SaveManyResult {
        if ($entities === []) {
            return new SaveManyResult(succeeded: 0, failed: 0, failedReferences: []);
        }

        $rows = \array_map($entityToAttributes, $entities);

        return $this->eloquentGateway->batchUpsertMany(
            modelClass: $this->getModelClass(),
            rows: $rows,
            uniqueBy: $upsertKeys,
            batchSize: $batchSize,
        );
    }

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
            } catch (DuplicateRecordException $e) {
                // Unique constraint violation on non-upsert column (e.g., SKU, GTIN)
                // This indicates a real data conflict that needs attention
                $failed++;
                $identifier = $this->getEntityIdentifier($entity);
                $failedReferences[] = $identifier;

                Log::error("{$this->getEntityTypeName()} has duplicate unique value - fix in source system", [
                    'identifier' => $identifier,
                    'constraint' => $e->constraint,
                    'table' => $e->table,
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
