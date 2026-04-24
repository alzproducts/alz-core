<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\CustomFields\Repositories;

use App\Application\Catalog\Results\CustomFieldResolutionResult;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;

/**
 * Eloquent implementation of the custom field repository.
 *
 * Two asymmetric roles under one class:
 *   • Write path (shopwired schema): upsert raw {@see CustomFieldDefinition} synced from ShopWired.
 *     Lives in `shopwired.custom_field_definitions`. Local catalog settings are never touched by sync.
 *   • Read path (cross-schema): eager-load the catalog-schema settings relations and return
 *     {@see ConfiguredFieldDefinition} so consumers see a single enriched value object.
 *
 * The model itself is ShopWired-owned (`App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel`)
 * and defines `hasOne` relations into the catalog-schema settings tables.
 *
 * @extends AbstractEloquentRepository<CustomFieldDefinition>
 */
final class EloquentCustomFieldRepository extends AbstractEloquentRepository implements CustomFieldRepositoryInterface
{
    /** @var class-string<CustomFieldDefinitionModel> */
    private const string MODEL_CLASS = CustomFieldDefinitionModel::class;

    private const string RESOURCE_TYPE = 'custom_field_definition';

    // ═════════════════════════════════════════════════════════════════════════
    // Write path — shopwired schema only (sync from ShopWired API)
    // Implements AbstractEloquentRepository<CustomFieldDefinition>
    // ═════════════════════════════════════════════════════════════════════════

    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    protected function getEntityIdentifier(object $entity): int
    {
        /** @var CustomFieldDefinition $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param CustomFieldDefinition $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'external_id' => $entity->id,
            ...CustomFieldDefinitionModel::fromDomainAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['external_id'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Read path — cross-schema (shopwired definitions + catalog settings)
    // Eager-loads `generalSettings` and `productSettings` on every query so
    // ConfiguredFieldDefinition assembly never issues lazy queries.
    // ═════════════════════════════════════════════════════════════════════════

    private const array SETTINGS_RELATIONS = ['generalSettings', 'productSettings'];

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByName(string $name): ?ConfiguredFieldDefinition
    {
        return $this->eloquentGateway->query(static function () use ($name): ?ConfiguredFieldDefinition {
            $model = CustomFieldDefinitionModel::query()
                ->with(self::SETTINGS_RELATIONS)
                ->where('name', $name)
                ->first();

            return $model?->toConfiguredDomain();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByItemType(CustomFieldItemType $itemType): array
    {
        return $this->eloquentGateway->query(static fn(): array => \array_values(
            CustomFieldDefinitionModel::query()
                ->with(self::SETTINGS_RELATIONS)
                ->where('item_type', $itemType->value)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn(CustomFieldDefinitionModel $model): ConfiguredFieldDefinition => $model->toConfiguredDomain())
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
    public function findAll(): array
    {
        return $this->eloquentGateway->query(static fn(): array => \array_values(
            CustomFieldDefinitionModel::query()
                ->with(self::SETTINGS_RELATIONS)
                ->orderBy('item_type')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn(CustomFieldDefinitionModel $model): ConfiguredFieldDefinition => $model->toConfiguredDomain())
                ->all(),
        ));
    }

    /**
     * {@inheritDoc}
     *
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findByExternalId(int $externalId): ConfiguredFieldDefinition
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId,
            relations: self::SETTINGS_RELATIONS,
            entityTypeName: self::RESOURCE_TYPE,
            mapper: static fn(CustomFieldDefinitionModel $model): ConfiguredFieldDefinition => $model->toConfiguredDomain(),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findInternalIdByExternalId(int $externalId): Uuid
    {
        $id = $this->eloquentGateway->query(static function () use ($externalId): ?string {
            /** @var string|null $id */
            $id = CustomFieldDefinitionModel::query()
                ->where('external_id', $externalId)
                ->value('id');

            return $id;
        });

        if ($id === null) {
            throw new RecordNotFoundException(
                resourceType: self::RESOURCE_TYPE,
                resourceId: $externalId,
            );
        }

        return Uuid::fromTrusted($id);
    }

    /**
     * {@inheritDoc}
     *
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findEnrichedWithInternalId(int $externalId): CustomFieldResolutionResult
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId,
            relations: self::SETTINGS_RELATIONS,
            entityTypeName: self::RESOURCE_TYPE,
            mapper: static fn(CustomFieldDefinitionModel $model): CustomFieldResolutionResult => new CustomFieldResolutionResult(
                internalId: Uuid::fromTrusted($model->id),
                definition: $model->toConfiguredDomain(),
            ),
        );
    }
}
