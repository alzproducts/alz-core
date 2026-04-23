<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\Enums\ProductType;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use Illuminate\Console\Command;

/**
 * TEMPORARY: Test how ShopWired handles costPrice field values.
 *
 * Useful for exploring cost price update behavior via the standard product
 * update endpoint. Price/salePrice use the dedicated POST products/prices endpoint.
 *
 * Delete after testing is complete.
 */
final class TestShopwiredCostPriceCommand extends Command
{
    protected $signature = 'dev:test-costprice
        {sku : Variation SKU to test against}
        {--product-id=2430112 : ShopWired product ID}
        {--value=0 : Value to send (numeric)}';

    protected $description = '[TEMP] Test ShopWired costPrice field behavior with different values';

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotAvailableException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws RecordNotFoundException When variation row not found in database
     */
    public function handle(
        ProductSyncService $syncService,
        BasicProductUpdateClientInterface $updateClient,
    ): int {
        /** @var string $sku */
        $sku = $this->argument('sku');
        $productId = (int) $this->option('product-id');

        $variation = $this->findVariation($syncService, $productId, $sku);

        if ($variation === null) {
            return self::FAILURE;
        }

        $this->sendCostPriceUpdate($updateClient, $variation, (float) $this->option('value'));

        return self::SUCCESS;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotAvailableException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function findVariation(ProductSyncService $syncService, int $productId, string $sku): ?ProductVariation
    {
        $product = $syncService->refreshById($productId);
        $variation = \array_find($product->variations ?? [], static fn(ProductVariation $v) => $v->sku === $sku);

        if ($variation === null) {
            $this->error("Variation with SKU '{$sku}' not found on product {$productId}");
        }

        return $variation;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotAvailableException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws RecordNotFoundException When variation row not found in database
     */
    private function sendCostPriceUpdate(BasicProductUpdateClientInterface $updateClient, ProductVariation $variation, float $value): void
    {
        $money = Money::exclusive($value);
        $this->warn("Sending: costPrice = {$value} (Money::exclusive) → gross: {$money->toGross()} (variation ID: {$variation->id})");

        $updateClient->update(new UpdateBasicProductCommand(
            identifier: IntId::from($variation->id),
            type: ProductType::Variation,
            costPrice: $money,
        ));

        $this->info('Update sent — check ShopWired UI and pail logs.');
    }
}
