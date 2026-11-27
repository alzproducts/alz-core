<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SaleSubmissionContext;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Notifications\Slack\ProductPricingUpdatedNotification;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(ProductPricingUpdatedNotification::class)]
final class ProductPricingUpdatedNotificationTest extends TestCase
{
    #[Test]
    public function updated_footer_shows_a_british_date_and_time(): void
    {
        $this->travelTo('2026-01-05 14:30:09');

        $texts = self::texts(new ProductPricingUpdatedNotification(productId: 42, priceChanges: [self::priceChange()]));

        self::assertContains('Updated at 05/01/2026 14:30:09', $texts);
    }

    #[Test]
    public function sale_ends_line_shows_a_british_date(): void
    {
        $texts = self::texts(new ProductPricingUpdatedNotification(
            productId: 42,
            priceChanges: [self::priceChange()],
            saleSettings: new SaleSettings(saleReason: 'Clearance', saleEndDate: new DateTimeImmutable('2026-02-03')),
        ));

        self::assertContains("*Sale reason:* Clearance\n*Sale ends:* 03/02/2026", $texts);
    }

    #[Test]
    public function sale_ended_line_shows_a_british_date(): void
    {
        $texts = self::texts(new ProductPricingUpdatedNotification(
            productId: 42,
            priceChanges: [self::priceChange()],
            saleSubmissionContext: new SaleSubmissionContext(
                removalReason: SaleRemovalReason::cases()[0],
                saleEndDate: new DateTimeImmutable('2026-02-03'),
            ),
        ));

        $matching = \array_filter($texts, static fn(string $text): bool => \str_ends_with($text, "\n*Sale ended:* 03/02/2026"));

        self::assertCount(1, $matching);
    }

    private static function priceChange(): SkuPriceChange
    {
        return new SkuPriceChange(
            sku: Sku::fromTrusted('ABC-1'),
            previousPrices: new ProductRetailPricing(Money::inclusive(10.00)),
            newPrices: new ProductRetailPricing(Money::inclusive(9.00)),
        );
    }

    /**
     * Every `text` string in the blocks, at any depth.
     *
     * @return list<string>
     */
    private static function texts(ProductPricingUpdatedNotification $notification): array
    {
        $payload = $notification->toSlack(new stdClass())->toArray();
        $texts = [];
        \array_walk_recursive($payload, static function (mixed $value, int|string $key) use (&$texts): void {
            if ($key === 'text' && \is_string($value)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }
}
