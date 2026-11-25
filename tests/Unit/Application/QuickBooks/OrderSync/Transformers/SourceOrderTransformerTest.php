<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowLineDTO;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Application\QuickBooks\OrderSync\Transformers\SourceOrderTransformer;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(SourceOrderTransformer::class)]
#[CoversClass(SourceOrderDTO::class)]
#[CoversClass(SourceOrderRowDTO::class)]
#[CoversClass(SourceOrderRowLineDTO::class)]
final class SourceOrderTransformerTest extends TestCase
{
    #[Test]
    public function a_row_without_a_defect_becomes_an_order(): void
    {
        $row = self::row([self::line(), self::line(sku: 'SKU-20', vatRate: '0')]);

        $source = SourceOrderTransformer::fromRow($row);

        self::assertSame([42, 77, OrderLifecycleStatus::Processing], [$source->reference, $source->externalId, $source->status]);
        self::assertSame($row->createdAt, $source->placedAt);
        self::assertNull($source->defect);

        $order = $source->order;
        self::assertNotNull($order);
        self::assertSame('42', $order->reference);
        self::assertSame($row->createdAt, $order->createdAt);
        self::assertSame(RawPaymentMethod::PayPal, $order->paymentMethod);
        self::assertSame([$row->billing, $row->delivery], [$order->billing, $order->delivery]);
        self::assertSame(
            ['buyer@example.test', '01234', 'Next Day', 'note', 'tx-1'],
            [$order->billingEmail, $order->billingPhone, $order->shippingMethod, $order->comments, $order->transactionId],
        );
        self::assertSame('4.990000', $order->shippingTotalNet->toString());
        self::assertSame(20.0, $order->shippingVatRate?->percentage);

        self::assertCount(2, $order->lines);
        [$first, $second] = $order->lines;
        self::assertSame(['SKU-10', 'Line', 2], [$first->sku->value, $first->title, $first->quantity]);
        self::assertSame(['8.333333', '1.666667', 20.0], [$first->unitPrice->toString(), $first->unitPriceVat->toString(), $first->vatRate?->percentage]);
        self::assertSame(['SKU-20', 0.0], [$second->sku->value, $second->vatRate?->percentage]);
    }

    #[Test]
    public function free_shipping_needs_no_shipping_rate(): void
    {
        $source = SourceOrderTransformer::fromRow(self::row(shippingTotalNet: '0.000000', shippingVatRate: null));

        self::assertNull($source->defect);
        self::assertNotNull($source->order);
        self::assertNull($source->order->shippingVatRate);
    }

    #[Test]
    public function an_order_without_lines_needs_no_shipping_rate(): void
    {
        $source = SourceOrderTransformer::fromRow(self::row([], shippingVatRate: null));

        self::assertNull($source->defect);
        self::assertSame([], $source->order?->lines);
    }

    /**
     * @return iterable<string, array{SourceOrderRowDTO, SourceOrderDefect}>
     */
    public static function defects(): iterable
    {
        $sku = self::line(sku: '');
        $vat = self::line(vatRate: '-1');
        $quantity = self::line(quantity: 0);

        yield 'payment method null' => [self::row(paymentMethod: null), SourceOrderDefect::PaymentMethodMissing];
        yield 'payment method unknown' => [self::row(paymentMethod: 'Barter'), SourceOrderDefect::PaymentMethodMissing];
        yield 'sku empty' => [self::row([$sku]), SourceOrderDefect::SkuMissing];
        yield 'sku blank' => [self::row([self::line(sku: '  ')]), SourceOrderDefect::SkuMissing];
        yield 'line rate missing' => [self::row([self::line(vatRate: null)]), SourceOrderDefect::VatRateInvalid];
        yield 'line rate negative' => [self::row([$vat]), SourceOrderDefect::VatRateInvalid];
        yield 'shipping rate missing on charged shipping' => [self::row(shippingVatRate: null), SourceOrderDefect::VatRateInvalid];
        yield 'shipping rate negative' => [self::row(shippingVatRate: '-1'), SourceOrderDefect::VatRateInvalid];
        yield 'shipping rate negative on free shipping' => [self::row(shippingTotalNet: '0', shippingVatRate: '-1'), SourceOrderDefect::VatRateInvalid];
        yield 'quantity zero' => [self::row([$quantity]), SourceOrderDefect::QuantityInvalid];
        yield 'quantity negative' => [self::row([self::line(quantity: -1)]), SourceOrderDefect::QuantityInvalid];
        yield 'second line' => [self::row([self::line(), $quantity]), SourceOrderDefect::QuantityInvalid];

        yield 'payment before sku' => [self::row([$sku], null), SourceOrderDefect::PaymentMethodMissing];
        yield 'payment before vat' => [self::row([$vat], null), SourceOrderDefect::PaymentMethodMissing];
        yield 'payment before quantity' => [self::row([$quantity], null), SourceOrderDefect::PaymentMethodMissing];
        yield 'sku before vat' => [self::row([$vat, $sku]), SourceOrderDefect::SkuMissing];
        yield 'sku before quantity' => [self::row([$quantity, $sku]), SourceOrderDefect::SkuMissing];
        yield 'vat before quantity' => [self::row([$quantity, $vat]), SourceOrderDefect::VatRateInvalid];
    }

    #[Test]
    #[DataProvider('defects')]
    public function the_first_defect_replaces_the_order(SourceOrderRowDTO $row, SourceOrderDefect $expected): void
    {
        $source = SourceOrderTransformer::fromRow($row);

        self::assertSame($expected, $source->defect);
        self::assertNull($source->order);
    }

    /**
     * @param list<SourceOrderRowLineDTO>|null $lines
     */
    private static function row(
        ?array $lines = null,
        ?string $paymentMethod = 'PayPal',
        string $shippingTotalNet = '4.990000',
        ?string $shippingVatRate = '20.0',
    ): SourceOrderRowDTO {
        return new SourceOrderRowDTO(
            reference: 42,
            externalId: 77,
            createdAt: new DateTimeImmutable('2026-11-01 10:00:00+00'),
            status: OrderLifecycleStatus::Processing,
            paymentMethod: $paymentMethod,
            billing: SyncOrderFixture::contact(),
            billingEmail: 'buyer@example.test',
            billingPhone: '01234',
            delivery: SyncOrderFixture::contact(name: 'Del Buyer'),
            shippingMethod: 'Next Day',
            shippingTotalNet: $shippingTotalNet,
            shippingVatRate: $shippingVatRate,
            comments: 'note',
            transactionId: 'tx-1',
            lines: $lines ?? [self::line()],
        );
    }

    private static function line(string $sku = 'SKU-10', int $quantity = 2, ?string $vatRate = '20'): SourceOrderRowLineDTO
    {
        return new SourceOrderRowLineDTO($sku, 'Line', $quantity, '8.333333', '1.666667', $vatRate);
    }
}
