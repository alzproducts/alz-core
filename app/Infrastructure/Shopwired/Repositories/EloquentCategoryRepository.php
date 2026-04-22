<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Catalog\Queries\CategoryListQueryParams;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Catalog\Category\Mappers\CategoryViewAssembler;
use App\Infrastructure\Persistence\EloquentGateway;
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

    public function __construct(
        DatabaseGatewayInterface $gateway,
        EloquentGateway $eloquentGateway,
        private readonly CategoryViewAssembler $viewAssembler,
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
     * @return PaginatedList<CategoryView>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function paginate(int $perPage, int $page, CategoryListQueryParams $params = new CategoryListQueryParams()): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: self::MODEL_CLASS,
            scope: static function (Builder $q) use ($params): void {
                if (! $params->includeInactive) {
                    $q->where('active', true);
                }

                if ($params->isMainCategory !== null) {
                    if ($params->isMainCategory) {
                        $q->whereRaw('custom_fields @> ?::jsonb', ['{"is_main_category": true}']);
                    } else {
                        $q->where(static function (Builder $q): void {
                            $q->whereRaw('NOT (custom_fields @> ?::jsonb)', ['{"is_main_category": true}'])
                                ->orWhereNull('custom_fields');
                        });
                    }
                }

                $q->orderBy('sort_order')->orderBy('title');
            },
            relations: [],
            mapper: fn(CategoryModel $model): CategoryView => $this->viewAssembler->toViewDomain($model, $params->includes),
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
    public function findCategoryForApi(IntId $categoryId, array $includes = []): CategoryView
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $categoryId->value,
            entityTypeName: 'Category',
            mapper: fn(CategoryModel $model): CategoryView => $this->viewAssembler->toViewDomain($model, $includes),
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
