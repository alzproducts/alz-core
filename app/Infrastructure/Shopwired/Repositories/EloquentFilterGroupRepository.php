<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\FilterGroupDefinitionModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of ShopWired filter group repository.
 *
 * Persists Domain FilterGroupDefinition entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<FilterGroupDefinition>
 */
final class EloquentFilterGroupRepository extends AbstractEloquentRepository implements FilterGroupRepositoryInterface
{
    /** @var class-string<FilterGroupDefinitionModel> */
    private const string MODEL_CLASS = FilterGroupDefinitionModel::class;

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getByOptionNo(int $optionNo): FilterGroupDefinition
    {
        /** @var FilterGroupDefinitionModel $model */
        $model = $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'option_no',
            value: $optionNo,
            entityTypeName: $this->getEntityTypeName(),
        );

        return $model->toDomain();
    }

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
            FilterGroupDefinitionModel::query()
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map(static fn(FilterGroupDefinitionModel $model): FilterGroupDefinition => $model->toDomain())
                ->all(),
        ));
    }

    /**
     * {@inheritDoc}
     *
     * @return PaginatedList<FilterGroupDefinition>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(int $perPage, int $page): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: self::MODEL_CLASS,
            scope: static function (Builder $q): void {
                $q->orderBy('sort_order')->orderBy('title');
            },
            relations: [],
            mapper: static fn(FilterGroupDefinitionModel $model): FilterGroupDefinition => $model->toDomain(),
            perPage: $perPage,
            page: $page,
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
        /** @var FilterGroupDefinition $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param FilterGroupDefinition $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...FilterGroupDefinitionModel::fromDomainAttributes($entity),
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
