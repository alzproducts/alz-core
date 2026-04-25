<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Catalog\UseCases\GetProductCustomFieldsUseCase;
use App\Application\Catalog\UseCases\GetProductUseCase;
use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\GetProductCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowProductRequestDTO;
use App\Presentation\Http\Api\Resources\CustomFieldValueResource;
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
        private GetProductCustomFieldsUseCase $getProductCustomFieldsUseCase,
    ) {}

    /**
     * List active products with optional includes.
     *
     * @throws InvalidCustomFieldValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function index(ListProductsRequestDTO $data): ResourceCollection
    {
        $result = $this->listProductsUseCase->execute($data->toQuery());

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
     * @throws RecordNotFoundException When product row not found in database
     * @throws InvalidEnumValueException
     */
    public function show(int $productId, ShowProductRequestDTO $data): ProductDetailResource
    {
        $result = $this->getProductUseCase->execute(
            new ProductDetailQueryParams(
                productId: IntId::from($productId),
                includes: \array_map(ProductInclude::fromValue(...), $data->validatedIncludes()),
            ),
        );

        return new ProductDetailResource($result);
    }

    /**
     * Get enriched custom fields for a product.
     *
     * @throws ResourceNotFoundException When product not found
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws RecordNotFoundException When product row not found in database
     */
    public function customFields(int $productId, GetProductCustomFieldsRequestDTO $data): ResourceCollection
    {
        $fields = $this->getProductCustomFieldsUseCase->execute(
            productId: $productId,
            fieldNames: $data->fieldNames(),
        );

        return CustomFieldValueResource::collection($fields);
    }
}
