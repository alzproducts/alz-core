<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\SaveCustomFieldGeneralSettingsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldGeneralSettingsRequestDTO;
use App\Presentation\Http\Api\Resources\ConfiguredFieldDefinitionResource;

/**
 * PUT /catalog/custom-field-definitions/{definitionId}/general-settings
 *
 * Upserts the `catalog.custom_field_general_settings` row associated with the
 * definition using partial-update semantics (absent fields left unchanged,
 * explicit nulls clear the column). Returns the full enriched definition so
 * the frontend can replace its cache entry in one round-trip.
 */
final readonly class CustomFieldGeneralSettingsController
{
    public function __construct(
        private SaveCustomFieldGeneralSettingsUseCase $useCase,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the ID
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(
        int $definitionId,
        UpdateCustomFieldGeneralSettingsRequestDTO $data,
    ): ConfiguredFieldDefinitionResource {
        $definition = $this->useCase->execute(
            definitionExternalId: $definitionId,
            command: $data->toCommand(),
        );

        return new ConfiguredFieldDefinitionResource($definition);
    }
}
