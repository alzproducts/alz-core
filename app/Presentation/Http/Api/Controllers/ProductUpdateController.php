<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateProductFieldsUseCase;
use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Application\Shopwired\UseCases\DispatchProductFreeDeliveryJobsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\FreeDeliveryUpdateItemDTO;
use App\Presentation\Http\Api\DTOs\GenerateVariantSkusRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateFreeDeliveryRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateProductFieldsRequestDTO;
use App\Presentation\Http\Api\Responses\GenerateVariantSkusResponseDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * Consumer API endpoints for product field, custom field, free delivery, and SKU updates.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 *
 * @see ProductPricingUpdateController for pricing endpoints
 * @see ProductRefreshController for refresh endpoints
 */
final readonly class ProductUpdateController
{
    public function __construct(
        private DispatchProductFreeDeliveryJobsUseCase $dispatchUseCase,
        private UpdateProductCustomFieldsUseCase $customFieldsUseCase,
        private UpdateProductFieldsUseCase $fieldsUseCase,
        private GenerateVariantSkusUseCase $generateVariantSkusUseCase,
    ) {}

    /**
     * Update scalar fields on a product.
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateFields(int $productId, UpdateProductFieldsRequestDTO $data): JsonResponse
    {
        $this->fieldsUseCase->execute(
            productId: IntId::from($productId),
            fields: $data->fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Update custom fields on a product.
     *
     * @throws ValidationFailedException When fields fail validation
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function updateCustomFields(int $productId, UpdateCustomFieldsRequestDTO $data): JsonResponse
    {
        $this->customFieldsUseCase->execute(
            productId: IntId::from($productId),
            rawFields: $data->custom_fields,
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Update free delivery type on multiple products.
     *
     * Dispatches jobs to update the free_delivery custom field.
     * Returns 202 Accepted with job dispatch summary.
     *
     * @throws ValueError When free delivery type is invalid (should not happen after validation)
     */
    public function updateFreeDelivery(UpdateFreeDeliveryRequestDTO $data): JsonResponse
    {
        /** @var list<SetFreeDeliveryCommand> $commands */
        $commands = \array_map(
            static fn(FreeDeliveryUpdateItemDTO $item): SetFreeDeliveryCommand => $item->toCommand(),
            \iterator_to_array($data->updates, preserve_keys: false),
        );
        $this->dispatchUseCase->execute($commands);

        return new JsonResponse(
            ['message' => 'Updates queued for processing', 'jobs_dispatched' => \count($commands)],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Generate Linnworks inventory items for SKU-less product variations.
     *
     * @throws InvalidSkuException When template SKU format is invalid (422)
     * @throws InvalidTemplateException When template has no default supplier (422)
     * @throws InvalidCustomFieldValueException When product custom fields invalid
     * @throws ResourceNotAvailableException When product not found in ShopWired (404)
     * @throws ResourceNotFoundException When template not found in Linnworks (404)
     * @throws RecordNotFoundException When standard sign product not found in local DB (404)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When APIs unavailable (503)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws InvalidApiResponseException When API response malformed
     * @throws LockAcquisitionException When SKU generation lock unavailable (503)
     * @throws DatabaseOperationFailedException When local refresh fails
     * @throws DuplicateRecordException When local refresh encounters duplicate
     */
    public function generateVariantSkus(int $productId, GenerateVariantSkusRequestDTO $data): GenerateVariantSkusResponseDTO
    {
        $result = $this->generateVariantSkusUseCase->execute($data->toCommand(IntId::from($productId)));

        return GenerateVariantSkusResponseDTO::fromResult($result);
    }
}
