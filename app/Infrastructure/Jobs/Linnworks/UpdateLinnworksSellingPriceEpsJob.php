<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Transformers\ProductRetailPricingTransformer;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webmozart\Assert\Assert;

/**
 * Syncs SellingPriceGross and SellingPriceNet EPs to Linnworks on price update.
 *
 * Reads current pricing from the local DB to ensure idempotency even when
 * retried after delays. Dispatched per SKU when retail pricing changes.
 */
final class UpdateLinnworksSellingPriceEpsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    public function __construct(
        public readonly IntId $productId,
        public readonly Sku $sku,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new HandleDatabaseExceptions(),
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(2)->toDateTimeImmutable();
    }

    /**
     * @throws RecordNotFoundException When product not found in local DB
     * @throws ResourceNotFoundException When stock item not found in Linnworks
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     */
    public function handle(
        ProductRepositoryInterface $productRepo,
        InventoryUpdateClientInterface $inventoryUpdateClient,
    ): void {
        $product = $productRepo->getProduct($this->productId);
        $pricingMap = ProductRetailPricingTransformer::fromProduct($product);

        $pricing = $pricingMap[$this->sku->value] ?? null;
        Assert::notNull($pricing, "SKU {$this->sku->value} not found in product {$this->productId->value} pricing map");

        $effectivePrice = $pricing->effectivePrice();
        $gross = \number_format($effectivePrice->toGross(), 2, '.', '');
        $net = \number_format($effectivePrice->toNet(), 2, '.', '');

        $inventoryUpdateClient->setExtendedProperties($this->sku, [
            ExtendedPropertyWrite::create(ExtendedPropertyName::SellingPriceGross, $gross),
            ExtendedPropertyWrite::create(ExtendedPropertyName::SellingPriceNet, $net),
        ]);
    }
}
