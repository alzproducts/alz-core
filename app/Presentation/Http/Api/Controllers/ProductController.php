<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\GetProductUseCase;
use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowProductRequestDTO;
use App\Presentation\Http\Api\Resources\ProductDetailResource;
use App\Presentation\Http\Api\Resources\ProductResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for product catalog.
 *
 * @throws InvalidCustomFieldValueException
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws ResourceNotFoundException
 */
final readonly class ProductController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListProductsUseCase $listProductsUseCase,
        private GetProductUseCase $getProductUseCase,
    ) {}

    /**
     * List active products with optional includes.
     *
     * @throws InvalidCustomFieldValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function index(ListProductsRequestDTO $data): ResourceCollection
    {
        $result = $this->listProductsUseCase->execute(
            perPage: $data->per_page,
            page: $data->page,
            includes: $data->validatedIncludes(),
        );

        return $this->paginatedResponse($result, ProductResource::class);
    }

    /**
     * Show a single product by ShopWired external ID with optional embeds.
     *
     * @throws ResourceNotFoundException When product not found
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function show(int $productId, ShowProductRequestDTO $data): ProductDetailResource
    {
        $result = $this->getProductUseCase->execute(
            productId: $productId,
            includes: $data->validatedIncludes(),
        );

        return new ProductDetailResource($result);
    }
}
