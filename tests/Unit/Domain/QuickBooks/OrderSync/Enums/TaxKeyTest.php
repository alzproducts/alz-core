<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxKey::class)]
final class TaxKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{SupportedVatRate, string, TaxKey, TaxKey}>
     */
    public static function taxKeys(): iterable
    {
        yield 'UK standard' => [SupportedVatRate::Standard, 'United Kingdom', TaxKey::Standard, TaxKey::Standard];
        yield 'UK zero' => [SupportedVatRate::Zero, 'United Kingdom', TaxKey::Zero, TaxKey::Zero];
        yield 'Ireland zero' => [SupportedVatRate::Zero, 'Ireland', TaxKey::EuGoods, TaxKey::EuShipping];
        yield 'Ireland standard' => [SupportedVatRate::Standard, 'Ireland', TaxKey::Standard, TaxKey::Standard];
        yield 'lower-case ireland' => [SupportedVatRate::Zero, 'ireland', TaxKey::Zero, TaxKey::Zero];
        yield 'padded Ireland' => [SupportedVatRate::Zero, ' Ireland', TaxKey::Zero, TaxKey::Zero];
        yield 'IE' => [SupportedVatRate::Zero, 'IE', TaxKey::Zero, TaxKey::Zero];
    }

    #[Test]
    #[DataProvider('taxKeys')]
    public function it_picks_goods_and_shipping_keys_by_rate_and_exact_ireland_match(
        SupportedVatRate $rate,
        string $deliveryCountry,
        TaxKey $goodsKey,
        TaxKey $shippingKey,
    ): void {
        self::assertSame($goodsKey, TaxKey::forGoods($rate, $deliveryCountry));
        self::assertSame($shippingKey, TaxKey::forShipping($rate, $deliveryCountry));
    }
}
