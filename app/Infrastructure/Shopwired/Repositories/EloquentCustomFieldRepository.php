<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;

/**
 * Eloquent implementation of ShopWired custom field repository.
 *
 * Read methods eager-load the local settings relations and return ConfiguredFieldDefinition
 * so callers never see a raw CustomFieldDefinition on the read path. Write path continues
 * to operate on CustomFieldDefinition — sync from ShopWired doesn't touch local settings.
 *
 * @extends AbstractEloquentRepository<CustomFieldDefinition>
 */
final class EloquentCustomFieldRepository extends AbstractEloquentRepository implements CustomFieldRepositoryInterface
{
    /** @var class-string<CustomFieldDefinitionModel> */
    private const string MODEL_CLASS = CustomFieldDefinitionModel::class;

    private const array SETTINGS_RELATIONS = ['generalSettings', 'productSettings'];

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

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

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
}
