<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\CategoryModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of ShopWired category repository.
 *
 * Persists Domain Category entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<Category>
 */
final class EloquentCategoryRepository extends AbstractEloquentRepository implements CategoryRepositoryInterface
{
    /** @var class-string<CategoryModel> */
    private const string MODEL_CLASS = CategoryModel::class;

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
            CategoryModel::query()
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map(static fn(CategoryModel $model): Category => $model->toDomain())
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
    public function findByExternalId(int $externalId): ?Category
    {
        return $this->eloquentGateway->query(static function () use ($externalId): ?Category {
            $model = CategoryModel::query()
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
    public function saveFromWebhook(Category $category, array $presentEmbeds = []): void
    {
        $attributes = CategoryModel::fromDomainAttributes($category);

        // Only include embed columns when they were present in the webhook payload
        if (! \in_array('parents', $presentEmbeds, true)) {
            unset($attributes['parent_ids']);
        }

        if (! \in_array('custom_fields', $presentEmbeds, true)) {
            unset($attributes['custom_fields']);
        }

        $this->eloquentGateway->upsertOne(
            modelClass: self::MODEL_CLASS,
            attributes: [
                'external_id' => $category->id,
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
     * @return PaginatedListDTO<CategoryView>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedListDTO
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
            mapper: static fn(CategoryModel $model): CategoryView => $model->toViewDomain($includes),
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
     */
    public function findCategoryForApi(IntId $categoryId, array $includes = []): CategoryView
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $categoryId->value,
            entityTypeName: 'Category',
            mapper: static fn(CategoryModel $model): CategoryView => $model->toViewDomain($includes),
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
        /** @var Category $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param Category $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...CategoryModel::fromDomainAttributes($entity),
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
