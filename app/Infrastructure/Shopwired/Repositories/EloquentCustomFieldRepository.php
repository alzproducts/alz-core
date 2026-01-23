<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent implementation of ShopWired custom field repository.
 *
 * Persists Domain CustomFieldDefinition entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * @extends AbstractEloquentRepository<CustomFieldDefinition>
 */
final class EloquentCustomFieldRepository extends AbstractEloquentRepository implements CustomFieldRepositoryInterface
{
    /** @var class-string<CustomFieldDefinitionModel> */
    private const string MODEL_CLASS = CustomFieldDefinitionModel::class;

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = [];

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param CustomFieldDefinition $entity
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        $this->gateway->transact(function () use ($entity): void {
            $this->upsertDefinition($entity);
        }, attempts: 3);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function findByName(string $name): ?CustomFieldDefinition
    {
        return $this->gateway->query(static function () use ($name): ?CustomFieldDefinition {
            $model = self::MODEL_CLASS::query()
                ->where('name', $name)
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
     * @throws InvalidApiResponseException
     */
    public function findByItemType(CustomFieldItemType $itemType): array
    {
        return $this->gateway->query(static fn(): array => \array_values(
            self::MODEL_CLASS::query()
                ->where('item_type', $itemType->value)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn(CustomFieldDefinitionModel $model): CustomFieldDefinition => $model->toDomain())
                ->all(),
        ));
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function findAll(): array
    {
        return $this->gateway->query(static fn(): array => \array_values(
            self::MODEL_CLASS::query()
                ->orderBy('item_type')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn(CustomFieldDefinitionModel $model): CustomFieldDefinition => $model->toDomain())
                ->all(),
        ));
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
    protected function getEagerLoadRelations(): array
    {
        return self::EAGER_LOAD_RELATIONS;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityIdentifier(object $entity): int
    {
        /** @var CustomFieldDefinition $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidApiResponseException
     */
    protected function mapModelToDomain(Model $model): CustomFieldDefinition
    {
        /** @var CustomFieldDefinitionModel $model */
        return $model->toDomain();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert custom field definition record based on external_id.
     */
    private function upsertDefinition(CustomFieldDefinition $definition): CustomFieldDefinitionModel
    {
        $attributes = CustomFieldDefinitionModel::fromDomainAttributes($definition);

        /** @var CustomFieldDefinitionModel $model */
        $model = self::MODEL_CLASS::query()->updateOrCreate(
            ['external_id' => $definition->id],
            $attributes,
        );

        return $model;
    }
}
