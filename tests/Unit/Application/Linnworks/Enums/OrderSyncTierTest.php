<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\Enums;

use App\Application\Linnworks\Enums\OrderSyncTier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderSyncTier Unit Tests.
 *
 * Tests the lookback window calculations for each tier.
 * The fromDate() method contains real date logic that PHPStan cannot validate.
 */
#[CoversClass(OrderSyncTier::class)]
final class OrderSyncTierTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Enum Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_has_exactly_four_cases(): void
    {
        $this->assertCount(4, OrderSyncTier::cases());
    }

    #[Test]
    #[DataProvider('tierValuesProvider')]
    public function it_has_correct_string_values(OrderSyncTier $tier, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $tier->value);
    }

    /**
     * @return array<string, array{OrderSyncTier, string}>
     */
    public static function tierValuesProvider(): array
    {
        return [
            'hourly' => [OrderSyncTier::Hourly, 'hourly'],
            'daily' => [OrderSyncTier::Daily, 'daily'],
            'weekly' => [OrderSyncTier::Weekly, 'weekly'],
            'monthly' => [OrderSyncTier::Monthly, 'monthly'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | fromDate() Lookback Windows
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('fromDateProvider')]
    public function it_calculates_correct_lookback_window(OrderSyncTier $tier, int $expectedSeconds, int $tolerance): void
    {
        $now = new DateTimeImmutable();
        $fromDate = $tier->fromDate();

        $actualDiff = $now->getTimestamp() - $fromDate->getTimestamp();

        $this->assertEqualsWithDelta($expectedSeconds, $actualDiff, $tolerance);
    }

    /**
     * @return array<string, array{OrderSyncTier, int, int}>
     */
    public static function fromDateProvider(): array
    {
        return [
            'hourly looks back 1 hour' => [OrderSyncTier::Hourly, 3_600, 5],
            'daily looks back 2 days' => [OrderSyncTier::Daily, 172_800, 5],
            'weekly looks back 2 weeks' => [OrderSyncTier::Weekly, 1_209_600, 5],
            'monthly looks back 28 days' => [OrderSyncTier::Monthly, 2_419_200, 5],
        ];
    }

    #[Test]
    public function from_date_returns_date_time_immutable(): void
    {
        $fromDate = OrderSyncTier::Hourly->fromDate();

        $this->assertInstanceOf(DateTimeImmutable::class, $fromDate);
    }

    #[Test]
    public function from_date_returns_date_in_the_past(): void
    {
        $now = new DateTimeImmutable();

        foreach (OrderSyncTier::cases() as $tier) {
            $fromDate = $tier->fromDate();
            $this->assertLessThan($now, $fromDate, "Tier {$tier->value} fromDate should be in the past");
        }
    }

    #[Test]
    public function tiers_have_progressively_wider_lookback_windows(): void
    {
        $hourly = OrderSyncTier::Hourly->fromDate();
        $daily = OrderSyncTier::Daily->fromDate();
        $weekly = OrderSyncTier::Weekly->fromDate();
        $monthly = OrderSyncTier::Monthly->fromDate();

        $this->assertGreaterThan($daily, $hourly, 'Hourly should be more recent than daily');
        $this->assertGreaterThan($weekly, $daily, 'Daily should be more recent than weekly');
        $this->assertGreaterThan($monthly, $weekly, 'Weekly should be more recent than monthly');
    }
}
