<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\SaveCustomFieldProductSettingsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldProductSettingsRequestDTO;
use App\Presentation\Http\Api\Resources\ConfiguredFieldDefinitionResource;

/**
 * PUT /catalog/custom-field-definitions/{definitionUuid}/product-settings
 *
 * Upserts the `catalog.custom_field_product_settings` row associated with a
 * product-type custom field definition using partial-update semantics. Keyed
 * by the internal UUID. Rejects non-product definitions with HTTP 422
 * (`code: product_settings_not_applicable`).
 */
final readonly class CustomFieldProductSettingsController
{
    public function __construct(
        private SaveCustomFieldProductSettingsUseCase $useCase,
    ) {}

    /**
     * @throws RecordNotFoundException When no definition matches the UUID
     * @throws ProductSettingsNotApplicableException When the definition is not a product-type field
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(
        string $definitionUuid,
        UpdateCustomFieldProductSettingsRequestDTO $data,
    ): ConfiguredFieldDefinitionResource {
        $definition = $this->useCase->execute(
            internalId: new Uuid($definitionUuid),
            command: $data->toCommand(),
        );

        return new ConfiguredFieldDefinitionResource($definition);
    }
}
