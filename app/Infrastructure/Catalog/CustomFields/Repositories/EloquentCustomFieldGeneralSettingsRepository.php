<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\CustomFields\Repositories;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Application\Contracts\Catalog\CustomFieldGeneralSettingsRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldGeneralSettingsModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

final readonly class EloquentCustomFieldGeneralSettingsRepository implements CustomFieldGeneralSettingsRepositoryInterface
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
    public function save(Uuid $definitionInternalId, SaveCustomFieldGeneralSettingsCommand $command): void
    {
        $this->eloquentGateway->upsertOne(
            modelClass: CustomFieldGeneralSettingsModel::class,
            attributes: [
                'custom_field_definition_id' => $definitionInternalId->value,
                ...self::touchedAttributes($command),
            ],
            uniqueBy: ['custom_field_definition_id'],
        );
    }

    /**
     * Build the INSERT/UPDATE attribute map from only the command's touched keys.
     *
     * The DB upsert treats the returned columns as the update column list, so
     * untouched columns keep their previous value on conflict (or fall back to
     * schema defaults on first create).
     *
     * @return array<string, mixed>
     */
    private static function touchedAttributes(SaveCustomFieldGeneralSettingsCommand $command): array
    {
        $all = [
            'tooltip' => $command->tooltip,
            'select_type' => $command->selectType?->value,
            'suggest_common_data' => $command->suggestCommonData,
            'admin_only' => $command->adminOnly,
            'field_validation_rule' => $command->validationRule?->value,
        ];

        return \array_intersect_key($all, \array_flip($command->touchedKeys));
    }
}
