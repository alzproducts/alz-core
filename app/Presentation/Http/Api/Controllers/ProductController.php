<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\GetProductCustomFieldsUseCase;
use App\Application\Catalog\UseCases\GetProductUseCase;
use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\UserInputValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\GetProductCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
use App\Presentation\Http\Api\DTOs\ShowProductRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateProductCustomFieldsRequestDTO;
use App\Presentation\Http\Api\Resources\ProductDetailResource;
use App\Presentation\Http\Api\Resources\ProductResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

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
        private UpdateProductCustomFieldsUseCase $updateProductCustomFieldsUseCase,
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

    /**
     * Get enriched custom fields for a product.
     *
     * @throws ResourceNotFoundException When product not found
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function customFields(int $productId, GetProductCustomFieldsRequestDTO $data): JsonResponse
    {
        $fields = $this->getProductCustomFieldsUseCase->execute(
            productId: $productId,
            fieldNames: $data->fieldNames(),
        );

        return new JsonResponse([
            'data' => \array_map(
                static fn(AbstractCustomFieldValue $field): array => $field->toArray(),
                $fields,
            ),
        ]);
    }

    /**
     * Update custom fields on a product.
     *
     * @throws UserInputValidationFailedException When fields fail validation
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function updateCustomFields(int $productId, UpdateProductCustomFieldsRequestDTO $data): JsonResponse
    {
        $this->updateProductCustomFieldsUseCase->execute(
            productId: IntId::from($productId),
            rawFields: $data->custom_fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
