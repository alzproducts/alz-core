<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Queries\CategoryListQueryParams;
use App\Application\Catalog\UseCases\GetCategoryCustomFieldsUseCase;
use App\Application\Catalog\UseCases\GetCategoryUseCase;
use App\Application\Catalog\UseCases\ListCategoriesUseCase;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\GetCategoryCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\ListCategoriesRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowCategoryRequestDTO;
use App\Presentation\Http\Api\Resources\CategoryDetailResource;
use App\Presentation\Http\Api\Resources\CategoryResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for product categories.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidCustomFieldValueException
 * @throws MissingRequiredDataException
 * @throws ResourceNotFoundException
 */
final readonly class CategoryController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListCategoriesUseCase $listCategoriesUseCase,
        private GetCategoryUseCase $getCategoryUseCase,
        private GetCategoryCustomFieldsUseCase $getCategoryCustomFieldsUseCase,
    ) {}

    /**
     * List categories with optional active filtering.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function index(ListCategoriesRequestDTO $data): ResourceCollection
    {
        $result = $this->listCategoriesUseCase->execute(
            perPage: $data->per_page,
            page: $data->page,
            params: new CategoryListQueryParams(
                includeInactive: $data->include_inactive,
                isMainCategory: $data->is_main_category,
            ),
        );

        return $this->paginatedResponse($result, CategoryResource::class);
    }

    /**
     * Show a single category by ShopWired external ID with optional embeds.
     *
     * @throws ResourceNotFoundException When category not found
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws InvalidEnumValueException
     * @throws MissingRequiredDataException
     */
    public function show(int $categoryId, ShowCategoryRequestDTO $data): CategoryDetailResource
    {
        $result = $this->getCategoryUseCase->execute(
            categoryId: $categoryId,
            includes: \array_map(CategoryInclude::fromValue(...), $data->validatedIncludes()),
        );

        return new CategoryDetailResource($result);
    }

    /**
     * Get enriched custom fields for a category.
     *
     * @throws ResourceNotFoundException When category not found
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function customFields(int $categoryId, GetCategoryCustomFieldsRequestDTO $data): JsonResponse
    {
        $fields = $this->getCategoryCustomFieldsUseCase->execute(
            categoryId: $categoryId,
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
