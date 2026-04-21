<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\RefreshCategoryViewUseCase;
use App\Application\Catalog\UseCases\UpdateCategoryCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateCategoryFieldsUseCase;
use App\Application\Shopwired\UseCases\SyncCategoriesUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\UpdateCategoryFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldsRequestDTO;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumer API endpoints for category updates.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 */
final readonly class CategoryUpdateController
{
    public function __construct(
        private UpdateCategoryFieldsUseCase $fieldsUseCase,
        private UpdateCategoryCustomFieldsUseCase $customFieldsUseCase,
        private RefreshCategoryViewUseCase $refreshUseCase,
        private SyncCategoriesUseCase $syncAllUseCase,
    ) {}

    /**
     * Update scalar fields on a category.
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateFields(int $categoryId, UpdateCategoryFieldsRequestDTO $data): JsonResponse
    {
        $this->fieldsUseCase->execute(
            categoryId: IntId::from($categoryId),
            fields: $data->fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Update custom fields on a category.
     *
     * @throws ValidationFailedException When fields fail validation
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function updateCustomFields(int $categoryId, UpdateCustomFieldsRequestDTO $data): JsonResponse
    {
        $this->customFieldsUseCase->execute(
            categoryId: IntId::from($categoryId),
            rawFields: $data->custom_fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Force-refresh a single category's data from ShopWired synchronously.
     *
     * @throws ResourceNotAvailableException When category not found in ShopWired (404)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When database save fails
     * @throws DuplicateRecordException When unique constraint violated
     */
    public function refresh(int $categoryId): JsonResponse
    {
        $this->refreshUseCase->execute(IntId::from($categoryId));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Force-refresh the full category list from ShopWired synchronously.
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws RuntimeException When API returns zero categories (unexpected)
     */
    public function refreshAll(): JsonResponse
    {
        $this->syncAllUseCase->execute();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
