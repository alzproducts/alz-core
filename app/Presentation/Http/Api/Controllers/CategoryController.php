<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\GetCategoryUseCase;
use App\Application\Catalog\UseCases\ListCategoriesUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\ListCategoriesRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowCategoryRequestDTO;
use App\Presentation\Http\Api\Resources\CategoryDetailResource;
use App\Presentation\Http\Api\Resources\CategoryResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for product categories.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws InvalidCustomFieldValueException
 * @throws ResourceNotFoundException
 */
final readonly class CategoryController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListCategoriesUseCase $listCategoriesUseCase,
        private GetCategoryUseCase $getCategoryUseCase,
    ) {}

    /**
     * List categories with optional active filtering.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     */
    public function index(ListCategoriesRequestDTO $data): ResourceCollection
    {
        $result = $this->listCategoriesUseCase->execute(
            perPage: $data->per_page,
            page: $data->page,
            includeInactive: $data->include_inactive,
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
     */
    public function show(int $categoryId, ShowCategoryRequestDTO $data): CategoryDetailResource
    {
        $result = $this->getCategoryUseCase->execute(
            categoryId: $categoryId,
            includes: $data->validatedIncludes(),
        );

        return new CategoryDetailResource($result);
    }
}
