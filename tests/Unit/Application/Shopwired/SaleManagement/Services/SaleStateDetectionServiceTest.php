<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\Services;

use App\Application\Shopwired\SaleManagement\Services\SaleStateDetectionService;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Domain\Catalog\Product\Events\SkuAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\SkuRemovedFromSaleEvent;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use Illuminate\Support\Facades\Event;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(SaleStateDetectionService::class)]
final class SaleStateDetectionServiceTest extends TestCase
{
    private SaleStateDetectionService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            ProductAddedToSaleEvent::class,
            ProductRemovedFromSaleEvent::class,
            SkuAddedToSaleEvent::class,
            SkuRemovedFromSaleEvent::class,
        ]);

        $this->service = new SaleStateDetectionService(
            events: Event::getFacadeRoot(),
        );
    }

    // ========================================================================
    // Happy Paths
    // ========================================================================

    #[Test]
    public function dispatches_added_to_sale_event_when_sku_added_to_sale(): void
    {
        $saleSettings = new SaleSettings(saleReason: 'Spring sale');
        $event = self::createEventWithAddedToSale($saleSettings);

        $this->service->detectAndDispatch($event);

        Event::assertDispatched(ProductAddedToSaleEvent::class, static fn(ProductAddedToSaleEvent $e): bool => $e->productId->value === 42
                && $e->saleSettings === $saleSettings);
        Event::assertDispatched(SkuAddedToSaleEvent::class, static fn(SkuAddedToSaleEvent $e): bool => $e->sku->value === 'TEST-001');
        Event::assertNotDispatched(ProductRemovedFromSaleEvent::class);
        Event::assertNotDispatched(SkuRemovedFromSaleEvent::class);
    }

    #[Test]
    public function dispatches_removed_from_sale_event_when_sku_removed_from_sale(): void
    {
        $saleSettings = SaleSettings::forRemoval(SaleRemovalReason::EndDateReached);
        $event = self::createEventWithRemovedFromSale($saleSettings);

        $this->service->detectAndDispatch($event);

        Event::assertDispatched(ProductRemovedFromSaleEvent::class, static fn(ProductRemovedFromSaleEvent $e): bool => $e->productId->value === 42
                && $e->saleSettings === $saleSettings);
        Event::assertDispatched(SkuRemovedFromSaleEvent::class, static fn(SkuRemovedFromSaleEvent $e): bool => $e->sku->value === 'TEST-001');
        Event::assertNotDispatched(ProductAddedToSaleEvent::class);
        Event::assertNotDispatched(SkuAddedToSaleEvent::class);
    }

    #[Test]
    public function dispatches_both_events_when_one_sku_added_and_another_removed(): void
    {
        $saleSettings = new SaleSettings(saleReason: 'Mixed changes');

        $addedChange = new SkuPriceChange(
            sku: Sku::fromTrusted('SKU-ADD'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00), salePrice: Money::inclusive(15.00)),
        );

        $removedChange = new SkuPriceChange(
            sku: Sku::fromTrusted('SKU-REM'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(30.00), salePrice: Money::inclusive(25.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(30.00)),
        );

        $event = new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [$addedChange, $removedChange],
            saleSettings: $saleSettings,
        );

        $this->service->detectAndDispatch($event);

        Event::assertDispatched(ProductAddedToSaleEvent::class);
        Event::assertDispatched(ProductRemovedFromSaleEvent::class);
        Event::assertDispatched(SkuAddedToSaleEvent::class, static fn(SkuAddedToSaleEvent $e): bool => $e->sku->value === 'SKU-ADD');
        Event::assertDispatched(SkuRemovedFromSaleEvent::class, static fn(SkuRemovedFromSaleEvent $e): bool => $e->sku->value === 'SKU-REM');
    }

    // ========================================================================
    // No Transition
    // ========================================================================

    #[Test]
    public function dispatches_no_events_when_no_sale_transitions(): void
    {
        $change = new SkuPriceChange(
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(25.00)),
        );

        $event = new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [$change],
        );

        $this->service->detectAndDispatch($event);

        Event::assertNotDispatched(ProductAddedToSaleEvent::class);
        Event::assertNotDispatched(ProductRemovedFromSaleEvent::class);
        Event::assertNotDispatched(SkuAddedToSaleEvent::class);
        Event::assertNotDispatched(SkuRemovedFromSaleEvent::class);
    }

    #[Test]
    public function dispatches_no_events_for_empty_price_changes(): void
    {
        $event = new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [],
        );

        $this->service->detectAndDispatch($event);

        Event::assertNotDispatched(ProductAddedToSaleEvent::class);
        Event::assertNotDispatched(ProductRemovedFromSaleEvent::class);
        Event::assertNotDispatched(SkuAddedToSaleEvent::class);
        Event::assertNotDispatched(SkuRemovedFromSaleEvent::class);
    }

    // ========================================================================
    // Assertion Failures
    // ========================================================================

    #[Test]
    public function throws_assertion_when_added_to_sale_but_no_sale_settings(): void
    {
        $event = self::createEventWithAddedToSale(saleSettings: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no SaleSettings provided');

        $this->service->detectAndDispatch($event);
    }

    #[Test]
    public function throws_assertion_when_removed_from_sale_but_no_sale_settings(): void
    {
        $event = self::createEventWithRemovedFromSale(saleSettings: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no SaleSettings provided');

        $this->service->detectAndDispatch($event);
    }

    // ========================================================================
    // Multi-SKU — Per-SKU Events for All Variations
    // ========================================================================

    #[Test]
    public function dispatches_sku_event_per_variation_when_multiple_skus_added(): void
    {
        $saleSettings = new SaleSettings(saleReason: 'Bulk sale');

        $first = new SkuPriceChange(
            sku: Sku::fromTrusted('FIRST'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00), salePrice: Money::inclusive(15.00)),
        );

        $second = new SkuPriceChange(
            sku: Sku::fromTrusted('SECOND'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(30.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(30.00), salePrice: Money::inclusive(25.00)),
        );

        $event = new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [$first, $second],
            saleSettings: $saleSettings,
        );

        $this->service->detectAndDispatch($event);

        // One product-level event for ShopWired
        Event::assertDispatched(ProductAddedToSaleEvent::class, 1);

        // One SKU-level event per variation for Linnworks
        Event::assertDispatched(SkuAddedToSaleEvent::class, 2);
        Event::assertDispatched(SkuAddedToSaleEvent::class, static fn(SkuAddedToSaleEvent $e): bool => $e->sku->value === 'FIRST');
        Event::assertDispatched(SkuAddedToSaleEvent::class, static fn(SkuAddedToSaleEvent $e): bool => $e->sku->value === 'SECOND');
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createEventWithAddedToSale(?SaleSettings $saleSettings): ProductPricingUpdatedEvent
    {
        $change = new SkuPriceChange(
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00), salePrice: Money::inclusive(15.00)),
        );

        return new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [$change],
            saleSettings: $saleSettings,
        );
    }

    private static function createEventWithRemovedFromSale(?SaleSettings $saleSettings): ProductPricingUpdatedEvent
    {
        $change = new SkuPriceChange(
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00), salePrice: Money::inclusive(15.00)),
            newPrices: new ProductRetailPricing(basePrice: Money::inclusive(20.00)),
        );

        return new ProductPricingUpdatedEvent(
            productId: IntId::fromTrusted(42),
            priceChanges: [$change],
            saleSettings: $saleSettings,
        );
    }
}
