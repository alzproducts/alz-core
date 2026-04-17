<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\GetBrandCustomFieldsUseCase;
use App\Application\Catalog\UseCases\GetBrandUseCase;
use App\Application\Catalog\UseCases\ListBrandsUseCase;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\GetBrandCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\ListBrandsRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowBrandRequestDTO;
use App\Presentation\Http\Api\Resources\BrandDetailResource;
use App\Presentation\Http\Api\Resources\BrandResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for product brands.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidCustomFieldValueException
 * @throws InvalidEnumValueException
 * @throws MissingRequiredDataException
 * @throws ResourceNotFoundException
 */
final readonly class BrandController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListBrandsUseCase $listBrandsUseCase,
        private GetBrandUseCase $getBrandUseCase,
        private GetBrandCustomFieldsUseCase $getBrandCustomFieldsUseCase,
    ) {}

    /**
     * List brands with optional active filtering.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function index(ListBrandsRequestDTO $data): ResourceCollection
    {
        $result = $this->listBrandsUseCase->execute(
            perPage: $data->per_page,
            page: $data->page,
            includeInactive: $data->include_inactive,
        );

        return $this->paginatedResponse($result, BrandResource::class);
    }

    /**
     * Show a single brand by ShopWired external ID with optional embeds.
     *
     * @throws ResourceNotFoundException When brand not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws InvalidEnumValueException
     * @throws MissingRequiredDataException
     */
    public function show(int $brandId, ShowBrandRequestDTO $data): BrandDetailResource
    {
        $result = $this->getBrandUseCase->execute(
            brandId: $brandId,
            includes: \array_map(BrandInclude::fromValue(...), $data->validatedIncludes()),
        );

        return new BrandDetailResource($result);
    }

    /**
     * Get enriched custom fields for a brand.
     *
     * @throws ResourceNotFoundException When brand not found
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function customFields(int $brandId, GetBrandCustomFieldsRequestDTO $data): JsonResponse
    {
        $fields = $this->getBrandCustomFieldsUseCase->execute(
            brandId: $brandId,
            fieldNames: $data->fieldNames(),
        );

        return new JsonResponse([
            'data' => \array_map(
                static fn(AbstractCustomFieldValue $field): array => $field->toArray(),
                $fields,
            ),
        ]);
    }
}
