<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\ValueObjects\Brand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\BrandModel;

/**
 * Eloquent implementation of ShopWired brand repository.
 *
 * Persists Domain Brand entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<Brand>
 */
final class EloquentBrandRepository extends AbstractEloquentRepository implements BrandRepositoryInterface
{
    /** @var class-string<BrandModel> */
    private const string MODEL_CLASS = BrandModel::class;

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findAll(): array
    {
        return $this->eloquentGateway->query(static fn(): array => \array_values(
            BrandModel::query()
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map(static fn(BrandModel $model): Brand => $model->toDomain())
                ->all(),
        ));
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByExternalId(int $externalId): ?Brand
    {
        return $this->eloquentGateway->query(static function () use ($externalId): ?Brand {
            $model = BrandModel::query()
                ->where('external_id', $externalId)
                ->first();

            return $model?->toDomain();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveFromWebhook(Brand $brand, array $presentEmbeds = []): void
    {
        $attributes = BrandModel::fromDomainAttributes($brand);

        // Only include embed columns when they were present in the webhook payload
        if (! \in_array('custom_fields', $presentEmbeds, true)) {
            unset($attributes['custom_fields']);
        }

        $this->eloquentGateway->upsertOne(
            modelClass: self::MODEL_CLASS,
            attributes: [
                'external_id' => $brand->id,
                ...$attributes,
            ],
            uniqueBy: ['external_id'],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteByExternalId(IntId $externalId): void
    {
        $deleted = $this->eloquentGateway->deleteWhere(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId->value,
        );

        if ($deleted === 0) {
            throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $externalId->value);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityIdentifier(object $entity): int
    {
        /** @var Brand $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param Brand $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...BrandModel::fromDomainAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['external_id'];
    }
}
