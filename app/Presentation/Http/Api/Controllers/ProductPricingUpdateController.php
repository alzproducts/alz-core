<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\DTOs\CostPriceItemDTO;
use App\Presentation\Http\Api\DTOs\UpdateCostPricesRequestDTO;
use App\Presentation\Http\Api\Responses\BulkUpdateResponseDTO;
use App\Presentation\Http\Api\Responses\PriceUpdateResponseDTO;
use App\Presentation\Http\Shopwired\DTOs\SkuPriceUpdateDTO;
use App\Presentation\Http\Shopwired\DTOs\UpdateProductPricesDTO;

/**
 * Consumer API endpoints for product pricing updates.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 */
final readonly class ProductPricingUpdateController
{
    public function __construct(
        private UpdateProductPricesUseCase $priceUseCase,
        private UpdateCostPriceBySupplierUseCase $costPriceUseCase,
    ) {}

    /**
     * Update retail prices for a single product's SKUs.
     *
     * @throws ResourceNotFoundException When the product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API transport fails
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws RecordNotFoundException When product row not found in database
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws ValidationFailedException When any submitted price fails VAT round-trip check
     */
    public function updatePrices(UpdateProductPricesDTO $data, string $productId): PriceUpdateResponseDTO
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

        return PriceUpdateResponseDTO::fromResult($result);
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
