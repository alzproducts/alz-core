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
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Money;
use Illuminate\Console\Command;

/**
 * TEMPORARY: Test how ShopWired handles different price field values.
 *
 * Useful for exploring price update behavior via the standard product
 * update endpoint. A new updatePrices endpoint exists that may handle
 * clearing/resetting prices — see GitHub issue for details.
 *
 * Delete after testing is complete.
 */
final class TestShopwiredCostPriceCommand extends Command
{
    protected $signature = 'dev:test-costprice
        {sku : Variation SKU to test against}
        {--product-id=2430112 : ShopWired product ID}
        {--field=salePrice : Price field to test (price, costPrice, salePrice)}
        {--value=0 : Value to send (numeric)}';

    protected $description = '[TEMP] Test ShopWired price field behavior with different values';

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(
        ProductSyncService $syncService,
        BasicProductUpdateClientInterface $updateClient,
    ): int {
        /** @var string $sku */
        $sku = $this->argument('sku');
        $productId = (int) $this->option('product-id');
        /** @var string $field */
        $field = $this->option('field');
        $value = (float) $this->option('value');

        if (! \in_array($field, ['price', 'costPrice', 'salePrice'], true)) {
            $this->error("Invalid field '{$field}'. Use: price, costPrice, salePrice");

            return self::FAILURE;
        }

        $product = $syncService->refreshById($productId);
        $variation = \array_find($product->variations, static fn(ProductVariation $v) => $v->sku === $sku);

        if ($variation === null) {
            $this->error("Variation with SKU '{$sku}' not found on product {$productId}");

            return self::FAILURE;
        }

        $money = Money::exclusive($value);
        $this->warn("Sending: {$field} = {$value} (Money::exclusive) → gross: {$money->toGross()} (variation ID: {$variation->id})");

        $updateClient->update(new UpdateBasicProductCommand(
            identifier: IntId::from($variation->id),
            type: ProductType::Variation,
            price: $field === 'price' ? $money : null,
            costPrice: $field === 'costPrice' ? $money : null,
            salePrice: $field === 'salePrice' ? $money : null,
        ));

        $this->info('Update sent — check ShopWired UI and pail logs.');

        return self::SUCCESS;
    }
}
