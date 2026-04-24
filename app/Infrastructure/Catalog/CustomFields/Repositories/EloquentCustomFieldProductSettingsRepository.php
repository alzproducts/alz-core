<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\CustomFields\Repositories;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldProductSettingsModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

final readonly class EloquentCustomFieldProductSettingsRepository implements CustomFieldProductSettingsRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function save(Uuid $definitionInternalId, SaveCustomFieldProductSettingsCommand $command): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: CustomFieldProductSettingsModel::class,
            attributes: [
                'custom_field_definition_id' => $definitionInternalId->value,
                ...self::touchedAttributes($command),
            ],
            uniqueBy: ['custom_field_definition_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function touchedAttributes(SaveCustomFieldProductSettingsCommand $command): array
    {
        $all = [
            'stock_item_update_mode' => $command->stockItemUpdateMode?->value,
        ];

        return \array_intersect_key($all, \array_flip($command->touchedKeys));
    }
}
