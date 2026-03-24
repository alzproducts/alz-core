<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\ListProductsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
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
 */
final readonly class ProductController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListProductsUseCase $listProductsUseCase,
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
}
