<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Shopwired;

use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Application\Shopwired\UseCases\DispatchProductFreeDeliveryJobsUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Requests\SetFreeDeliveryRequest;
use App\Presentation\Http\Shopwired\DTOs\SkuPriceUpdateDTO;
use App\Presentation\Http\Shopwired\DTOs\UpdateProductPricesDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * HTTP endpoints for ShopWired product updates.
 *
 * All endpoints require Supabase JWT authentication.
 */
final readonly class ProductUpdateController
{
    public function __construct(
        private DispatchProductFreeDeliveryJobsUseCase $dispatchUseCase,
        private UpdateProductPricesUseCase $priceUseCase,
    ) {}

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
            [
                'message' => 'Updates queued for processing',
                'jobs_dispatched' => \count($commands),
            ],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Update retail prices for a single product's SKUs.
     *
     * Accepts a batch of SKU price changes. All SKUs must belong to the same
     * product — enforced internally by the use case. The {productId} URL
     * segment is for API clarity only and is not passed to the use case.
     *
     * Returns 200 with a structured result: succeeded count, skipped SKUs,
     * and any permanent/temporary failures.
     *
     * @throws ResourceNotFoundException When the product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API transport fails
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     */
    public function updatePrices(UpdateProductPricesDTO $data, string $productId): JsonResponse
    {
        /** @var list<UpdatePriceCommand> $commands */
        $commands = [];

        foreach ($data->skuUpdates as $skuUpdate) {
            /** @var SkuPriceUpdateDTO $skuUpdate */
            $commands[] = $skuUpdate->toCommand();
        }

        $result = $this->priceUseCase->execute($commands, $data->saleSettings?->toDomain());

        return new JsonResponse([
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'skipped' => \array_map(
                static fn(SkippedPriceUpdateResult $item): array => [
                    'sku' => $item->sku->value,
                    'reason' => $item->reason,
                ],
                $result->skipped,
            ),
            'permanent_failures' => \array_map(
                static fn(FailedPriceUpdateResult $item): array => [
                    'sku' => $item->sku?->value,
                    'error' => $item->error,
                ],
                $result->permanentFailures,
            ),
            'temporary_failures' => \array_map(
                static fn(FailedPriceUpdateResult $item): array => [
                    'sku' => $item->sku?->value,
                    'error' => $item->error,
                ],
                $result->temporaryFailures,
            ),
        ]);
    }
}
