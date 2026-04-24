<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\GetConfiguredFieldDefinitionUseCase;
use App\Application\Catalog\UseCases\ListConfiguredFieldDefinitionsUseCase;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\Resources\ConfiguredFieldDefinitionResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for enriched custom field definitions (read surface).
 *
 * Pairs the ShopWired-synced definitions with the local catalog-schema settings
 * so the frontend can render and edit them from a single resource.
 */
final readonly class CustomFieldDefinitionController
{
    public function __construct(
        private ListConfiguredFieldDefinitionsUseCase $listUseCase,
        private GetConfiguredFieldDefinitionUseCase $getUseCase,
    ) {}

    /**
     * List all custom field definitions with their local settings blocks.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function index(): ResourceCollection
    {
        /** @var list<ConfiguredFieldDefinition> $definitions */
        $definitions = $this->listUseCase->execute();

        return ConfiguredFieldDefinitionResource::collection($definitions);
    }

    /**
     * Show one custom field definition by its ShopWired external ID.
     *
     * @throws RecordNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function show(int $definitionId): ConfiguredFieldDefinitionResource
    {
        return new ConfiguredFieldDefinitionResource(
            $this->getUseCase->execute($definitionId),
        );
    }
}
