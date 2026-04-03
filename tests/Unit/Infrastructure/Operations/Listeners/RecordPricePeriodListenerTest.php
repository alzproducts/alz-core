<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Operations\Listeners;

use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Operations\RecordPricePeriodJob;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(RecordPricePeriodListener::class)]
final class RecordPricePeriodListenerTest extends TestCase
{
    #[Test]
    public function dispatches_record_price_period_job_with_event_data(): void
    {
        Queue::fake();

        $event = new SkuRetailPricingUpdatedEvent(
            productId: IntId::fromTrusted(1),
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
            newPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(25.00),
            ),
        );

        (new RecordPricePeriodListener())->handle($event);

        Queue::assertPushed(RecordPricePeriodJob::class, static fn(RecordPricePeriodJob $job): bool => $job->sku->value === $event->sku->value
                && $job->newPrices === $event->newPrices);
    }
}
