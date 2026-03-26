<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\UpdateBrandCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateBrandFieldsUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\UpdateBrandFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldsRequestDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumer API endpoints for brand updates.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 */
final readonly class BrandUpdateController
{
    public function __construct(
        private UpdateBrandFieldsUseCase $fieldsUseCase,
        private UpdateBrandCustomFieldsUseCase $customFieldsUseCase,
    ) {}

    /**
     * Update scalar fields on a brand.
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateFields(int $brandId, UpdateBrandFieldsRequestDTO $data): JsonResponse
    {
        $this->fieldsUseCase->execute(
            brandId: IntId::from($brandId),
            fields: $data->fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Update custom fields on a brand.
     *
     * @throws ValidationFailedException When fields fail validation
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function updateCustomFields(int $brandId, UpdateCustomFieldsRequestDTO $data): JsonResponse
    {
        $this->customFieldsUseCase->execute(
            brandId: IntId::from($brandId),
            rawFields: $data->custom_fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
