<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaleSettings::class)]
final class SaleSettingsTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $startDate = new DateTimeImmutable('2026-03-23');
        $endDate = new DateTimeImmutable('2026-04-01');

        $settings = new SaleSettings(
            saleReason: 'Spring clearance',
            saleComments: 'End of season',
            saleStartDate: $startDate,
            saleEndDate: $endDate,
            saleEndsStock: 5,
            removalReason: SaleRemovalReason::Manual,
        );

        self::assertSame('Spring clearance', $settings->saleReason);
        self::assertSame('End of season', $settings->saleComments);
        self::assertSame($startDate, $settings->saleStartDate);
        self::assertSame($endDate, $settings->saleEndDate);
        self::assertSame(5, $settings->saleEndsStock);
        self::assertSame(SaleRemovalReason::Manual, $settings->removalReason);
    }

    #[Test]
    public function constructor_defaults_optional_fields_to_null(): void
    {
        $settings = new SaleSettings(saleReason: 'Flash sale');

        self::assertSame('Flash sale', $settings->saleReason);
        self::assertNull($settings->saleComments);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
        self::assertNull($settings->removalReason);
    }

    #[Test]
    public function for_removal_creates_settings_with_reason_label(): void
    {
        $settings = SaleSettings::forRemoval(SaleRemovalReason::EndDateReached);

        self::assertSame('Sale end date reached', $settings->saleReason);
        self::assertSame(SaleRemovalReason::EndDateReached, $settings->removalReason);
    }

    #[Test]
    public function for_removal_leaves_optional_fields_null(): void
    {
        $settings = SaleSettings::forRemoval(SaleRemovalReason::ProductInactive);

        self::assertNull($settings->saleComments);
        self::assertNull($settings->saleStartDate);
        self::assertNull($settings->saleEndDate);
        self::assertNull($settings->saleEndsStock);
    }

    #[Test]
    public function for_removal_uses_correct_label_for_each_reason(): void
    {
        foreach (SaleRemovalReason::cases() as $reason) {
            $settings = SaleSettings::forRemoval($reason);

            self::assertSame($reason->label(), $settings->saleReason);
            self::assertSame($reason, $settings->removalReason);
        }
    }
}
