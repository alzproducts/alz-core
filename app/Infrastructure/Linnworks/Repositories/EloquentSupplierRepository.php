<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\SupplierRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Supplier;
use App\Infrastructure\Linnworks\Mappers\SupplierMapper;
use App\Infrastructure\Linnworks\Models\SupplierModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Eloquent implementation of Linnworks supplier directory repository.
 *
 * Persists Domain Supplier entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on Linnworks pk_supplier_id for idempotent sync.
 *
 * @extends AbstractEloquentRepository<Supplier>
 */
final class EloquentSupplierRepository extends AbstractEloquentRepository implements SupplierRepositoryInterface
{
    /** @var class-string<SupplierModel> */
    private const string MODEL_CLASS = SupplierModel::class;

    /**
     * {@inheritDoc}
     *
     * @param list<Supplier> $suppliers
     */
    public function saveSuppliersBulk(array $suppliers): SaveManyResult
    {
        return $this->saveManyBulk(
            entities: $suppliers,
            entityToAttributes: static fn(Supplier $supplier): array => SupplierMapper::toModelAttributes($supplier),
            upsertKeys: ['pk_supplier_id'],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteWhereNotIn(array $pkSupplierIds): int
    {
        return $this->reconcileDelete('pk_supplier_id', $pkSupplierIds);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var Supplier $entity */
        return $entity->pkSupplierId;
    }

    /**
     * @param Supplier $entity
     *
     * @return array<string, mixed>
     */
    protected function entityToAttributes(object $entity): array
    {
        return SupplierMapper::toModelAttributes($entity);
    }

    protected function getUpsertKeys(): array
    {
        return ['pk_supplier_id'];
    }
}
