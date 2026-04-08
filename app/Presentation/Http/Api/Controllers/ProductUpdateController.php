<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\RefreshProductViewUseCase;
use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateProductFieldsUseCase;
use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Application\Shopwired\UseCases\DispatchProductFreeDeliveryJobsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\PartialPersistenceFailureException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\CostPriceItemDTO;
use App\Presentation\Http\Api\DTOs\UpdateCostPricesRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldsRequestDTO;
use App\Presentation\Http\Api\DTOs\UpdateProductFieldsRequestDTO;
use App\Presentation\Http\Api\Responses\BulkUpdateResponseDTO;
use App\Presentation\Http\Requests\SetFreeDeliveryRequest;
use App\Presentation\Http\Shopwired\DTOs\SkuPriceUpdateDTO;
use App\Presentation\Http\Shopwired\DTOs\UpdateProductPricesDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * Consumer API endpoints for product updates.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 */
final readonly class ProductUpdateController
{
    public function __construct(
        private DispatchProductFreeDeliveryJobsUseCase $dispatchUseCase,
        private UpdateProductPricesUseCase $priceUseCase,
        private UpdateProductCustomFieldsUseCase $customFieldsUseCase,
        private UpdateProductFieldsUseCase $fieldsUseCase,
        private UpdateCostPriceBySupplierUseCase $costPriceUseCase,
        private RefreshProductViewUseCase $refreshUseCase,
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
     * Force-refresh a product's data from ShopWired and Linnworks synchronously.
     *
     * @throws ResourceNotAvailableException When product not found in ShopWired (404)
     * @throws ResourceNotFoundException When Linnworks resource not found
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When database save fails
     * @throws DuplicateRecordException When unique constraint violated
     * @throws MissingRequiredDataException When product has no SKUs or no matching stock items
     * @throws PartialPersistenceFailureException When some stock items fail to persist
     */
    public function refresh(int $productId): JsonResponse
    {
        $this->refreshUseCase->execute(IntId::from($productId));

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
    public function updateFreeDelivery(SetFreeDeliveryRequest $request): JsonResponse
    {
        /** @var list<array{identifier: string|int, type: string}> $updates */
        $updates = $request->validated('updates');
        $commands = \array_map(
            static fn(array $update): SetFreeDeliveryCommand => new SetFreeDeliveryCommand(
                $update['identifier'],
                FreeDeliveryType::fromString($update['type']),
            ),
            $updates,
        );
        $this->dispatchUseCase->execute($commands);

        return new JsonResponse(
            ['message' => 'Updates queued for processing', 'jobs_dispatched' => \count($commands)],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Update retail prices for a single product's SKUs.
     *
     * @throws ResourceNotFoundException When the product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API transport fails
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws ValidationFailedException When any submitted price fails VAT round-trip check
     */
    public function updatePrices(UpdateProductPricesDTO $data, string $productId): JsonResponse
    {
        /** @var list<UpdatePriceCommand> $commands */
        $commands = [];
        foreach ($data->skuUpdates as $skuUpdate) {
            /** @var SkuPriceUpdateDTO $skuUpdate */
            $commands[] = $skuUpdate->toCommand();
        }

        $result = $this->priceUseCase->execute(
            IntId::from((int) $productId),
            $commands,
            $data->saleSettings?->toDomain(),
        );

        return new JsonResponse($this->buildPriceUpdateResponse($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPriceUpdateResponse(PriceUpdateResult $result): array
    {
        return [
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'skipped' => \array_map(
                static fn(SkippedPriceUpdateResult $item): array => [
                    'sku' => $item->sku->value,
                    'reason' => $item->reason,
                ],
                $result->skipped,
            ),
            'permanent_failures' => self::mapFailures($result->permanentFailures),
            'temporary_failures' => self::mapFailures($result->temporaryFailures),
        ];
    }

    /**
     * @param list<FailedPriceUpdateResult> $failures
     *
     * @return list<array{sku: string|null, error: string}>
     */
    private static function mapFailures(array $failures): array
    {
        return \array_map(
            static fn(FailedPriceUpdateResult $item): array => [
                'sku' => $item->sku?->value,
                'error' => $item->error,
            ],
            $failures,
        );
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
     * Bulk update cost prices for multiple SKUs with a shared supplier.
     *
     * @throws InvalidSkuException When any SKU format is invalid
     * @throws ValidationFailedException When any SKU lacks the specified supplier (422)
     * @throws ResourceNotFoundException When supplier not found in Linnworks (404)
     * @throws InvalidApiRequestException When parameters invalid (400)
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException On local DB query failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function updateCostPrices(UpdateCostPricesRequestDTO $data): BulkUpdateResponseDTO
    {
        /** @var list<UpdateCostPriceCommand> $commands */
        $commands = \array_map(
            static fn(CostPriceItemDTO $item): UpdateCostPriceCommand => $item->toCommand(),
            \iterator_to_array($data->items, preserve_keys: false),
        );

        $result = $this->costPriceUseCase->execute($data->supplierName, $commands);

        return BulkUpdateResponseDTO::fromCostPriceResult($result);
    }
}
