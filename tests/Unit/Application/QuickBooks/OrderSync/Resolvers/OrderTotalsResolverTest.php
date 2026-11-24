<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderTotalsResolver;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderTotalsResolver::class)]
final class OrderTotalsResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{non-empty-list<array{string, TaxKey}>, array{string, TaxKey}|null, string}>
     */
    public static function documents(): iterable
    {
        yield 'standard lines rounded before summing' => [[['0.02', TaxKey::Standard], ['0.02', TaxKey::Standard]], null, '0.04'];
        yield 'standard VAT rounded up' => [[['0.03', TaxKey::Standard]], null, '0.04'];
        yield 'zero' => [[['10.00', TaxKey::Zero]], null, '10.00'];
        yield 'eu goods and eu shipping' => [[['10.00', TaxKey::EuGoods]], ['5.00', TaxKey::EuShipping], '15.00'];
        yield 'standard shipping' => [[['25.00', TaxKey::Standard]], ['4.99', TaxKey::Standard], '35.99'];
    }

    /**
     * @param non-empty-list<array{string, TaxKey}> $goods
     * @param array{string, TaxKey}|null            $shipping
     */
    #[Test]
    #[DataProvider('documents')]
    public function the_expected_total_adds_each_lines_own_rounded_vat(array $goods, ?array $shipping, string $expected): void
    {
        $address = StructuredAddressDTO::fromContact(new OrderContactDTO('', '', '', '', '', '', '', ''));
        $document = new DocumentCoreDTO(
            docNumber: '1001',
            txnDate: new DateTimeImmutable('2026-11-20'),
            billEmail: '',
            customer: new CustomerDraftDTO('Jo Bloggs', 'Jo', 'Bloggs', '', '', '', $address),
            goodsLines: \array_map(static fn(array $line): GoodsLineDraftDTO => new GoodsLineDraftDTO(
                Sku::fromString('SKU-A'),
                '',
                DecimalMoney::fromString($line[0]),
                1,
                DecimalMoney::fromString($line[0]),
                $line[1],
            ), $goods),
            shippingLine: $shipping === null ? null : new ShippingLineDraftDTO(DecimalMoney::fromString($shipping[0]), $shipping[1]),
        );

        self::assertSame($expected, OrderTotalsResolver::expectedTotal($document)->toString());
    }

    /**
     * @return iterable<string, array{string, string, string|null, string}>
     */
    public static function storedTotals(): iterable
    {
        yield 'rounded once after summing' => ['3.335000', '3.335000', '3.334000', '10.00'];
        yield 'half a penny rounds up' => ['1.004000', '0.001000', '0.000000', '1.01'];
        yield 'below half a penny rounds down' => ['1.004000', '0.000999', '0.000000', '1.00'];
        yield 'null VAT is zero' => ['25.000000', '4.990000', null, '29.99'];
    }

    #[Test]
    #[DataProvider('storedTotals')]
    public function the_shopwired_total_is_summed_exactly_then_rounded(string $subTotal, string $shipping, ?string $tax, string $expected): void
    {
        $totals = new SourceOrderTotalsDTO(1, OrderLifecycleStatus::Processing, $subTotal, $shipping, $tax);

        self::assertSame($expected, OrderTotalsResolver::shopwiredTotal($totals)->toString());
    }
}
