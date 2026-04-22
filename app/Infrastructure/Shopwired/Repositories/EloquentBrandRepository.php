<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\ValueObjects\Brand;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Catalog\Brand\Mappers\BrandViewAssembler;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\BrandModel;
use Illuminate\Database\Eloquent\Builder;

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

    public function __construct(
        DatabaseGatewayInterface $gateway,
        EloquentGateway $eloquentGateway,
        private readonly BrandViewAssembler $viewAssembler,
    ) {
        parent::__construct($gateway, $eloquentGateway);
    }

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

    /**
     * {@inheritDoc}
     *
     * @return PaginatedList<BrandView>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function paginate(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: self::MODEL_CLASS,
            scope: static function (Builder $q) use ($includeInactive): void {
                if (! $includeInactive) {
                    $q->where('active', true);
                }

                $q->orderBy('sort_order')->orderBy('title');
            },
            relations: [],
            mapper: fn(BrandModel $model): BrandView => $this->viewAssembler->toViewDomain($model, $includes),
            perPage: $perPage,
            page: $page,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function findBrandForApi(IntId $brandId, array $includes = []): BrandView
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $brandId->value,
            entityTypeName: 'Brand',
            mapper: fn(BrandModel $model): BrandView => $this->viewAssembler->toViewDomain($model, $includes),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

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
