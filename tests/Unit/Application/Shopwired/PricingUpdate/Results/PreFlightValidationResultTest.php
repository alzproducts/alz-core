<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\PricingUpdate\Results;

use App\Application\Shopwired\PricingUpdate\Results\PreFlightValidationResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreFlightValidationResult::class)]
final class PreFlightValidationResultTest extends TestCase
{
    #[Test]
    public function has_validated_returns_true_when_validated_not_empty(): void
    {
        $resolved = self::createResolved('SKU-1');

        $result = new PreFlightValidationResult(
            validated: [$resolved],
            skipped: [],
            permanentFailures: [],
        );

        self::assertTrue($result->hasValidated());
    }

    #[Test]
    public function has_validated_returns_false_when_validated_empty(): void
    {
        $result = new PreFlightValidationResult(
            validated: [],
            skipped: [new SkippedPriceUpdateResult(Sku::fromTrusted('SKU-1'), 'unchanged')],
            permanentFailures: [],
        );

        self::assertFalse($result->hasValidated());
    }

    #[Test]
    public function resolved_by_sku_builds_keyed_map(): void
    {
        $resolved1 = self::createResolved('SKU-1');
        $resolved2 = self::createResolved('SKU-2');

        $result = new PreFlightValidationResult(
            validated: [$resolved1, $resolved2],
            skipped: [],
            permanentFailures: [],
        );

        $map = $result->resolvedBySku();

        self::assertCount(2, $map);
        self::assertArrayHasKey('SKU-1', $map);
        self::assertArrayHasKey('SKU-2', $map);
        self::assertSame($resolved1, $map['SKU-1']);
        self::assertSame($resolved2, $map['SKU-2']);
    }

    #[Test]
    public function resolved_by_sku_returns_empty_map_when_no_validated(): void
    {
        $result = new PreFlightValidationResult(
            validated: [],
            skipped: [],
            permanentFailures: [],
        );

        self::assertSame([], $result->resolvedBySku());
    }

    private static function createResolved(string $sku): ResolvedPriceUpdate
    {
        $current = new ProductRetailPricing(basePrice: Money::inclusive(20.00));
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted($sku),
            price: Money::inclusive(25.00),
        );

        return ResolvedPriceUpdate::fromCommand($command, $current);
    }
}
