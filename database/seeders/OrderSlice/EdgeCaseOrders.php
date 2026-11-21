<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Application\Enums\FixtureSku;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use Database\Seeders\ProductSlice\SeedIds;

/**
 * The fixed edge-case orders: literal values, independent of the seed and of any profile.
 */
final class EdgeCaseOrders
{
    public const string MISSING_SKU = 'NOT-IN-POOL-404';

    public const string LEGACY_SKU = 'LEGACY-SKU-001';

    private const int HOUR = 3_600;

    /**
     * @return list<EdgeCaseOrder>
     */
    public static function all(): array
    {
        $orders = [];
        $synced = ExpectedOutcome::Synced;

        foreach (RawPaymentMethod::cases() as $method) {
            if ($method === RawPaymentMethod::Credit || $method === RawPaymentMethod::Offline) {
                continue;
            }

            $orders[] = self::edge(EdgeCase::PaymentMethod, $synced, \count($orders) + 1, $method);
        }

        $orders[] = self::edge(EdgeCase::CreditPaymentMethod, ExpectedOutcome::SkippedPaymentMethodUnmapped, \count($orders) + 1, RawPaymentMethod::Credit);
        $orders[] = self::edge(EdgeCase::OfflinePaymentMethod, ExpectedOutcome::SkippedPaymentMethodUnmapped, \count($orders) + 1, RawPaymentMethod::Offline);
        $orders[] = self::edge(EdgeCase::Cancelled, ExpectedOutcome::SkippedCancelled, \count($orders) + 1, status: OrderStatusType::Cancelled);
        $orders[] = self::edited(\count($orders) + 1);
        $orders[] = self::edge(EdgeCase::InvoiceWithCompany, $synced, \count($orders) + 1, RawPaymentMethod::AdminOrder, company: 'Hartley Care Homes Ltd');
        $orders[] = self::edge(EdgeCase::SalesReceiptWithoutCompany, $synced, \count($orders) + 1, RawPaymentMethod::OpayoHosted, name: 'Priya Shah');
        $orders[] = self::edge(EdgeCase::NameCleaning, $synced, \count($orders) + 1, RawPaymentMethod::AdminOrder, name: 'Morgan: Ellis; Ward: Ross; Hale');
        $orders[] = self::edge(EdgeCase::NameApostrophe, $synced, \count($orders) + 1, RawPaymentMethod::AdminOrder, name: "Siobhan O'Neill");
        $orders[] = self::edge(EdgeCase::DisplayNameCaseVariant, $synced, \count($orders) + 1, RawPaymentMethod::AdminOrder, name: 'Alex Carter');
        $orders[] = self::edge(EdgeCase::DisplayNameCaseVariant, $synced, \count($orders) + 1, RawPaymentMethod::AdminOrder, name: 'alex carter');
        $orders[] = self::edge(EdgeCase::CountryIreland, $synced, \count($orders) + 1, country: 'Ireland');
        $orders[] = self::edge(EdgeCase::CountryIrelandVariant, $synced, \count($orders) + 1, country: 'Republic of Ireland');
        $orders[] = self::edge(EdgeCase::ZeroRated, $synced, \count($orders) + 1, lines: [LineSpec::zeroRated(FixtureSku::Sku003->value, 12.50, 2)]);
        $orders[] = self::edge(EdgeCase::VatRelief, $synced, \count($orders) + 1, lines: [
            LineSpec::zeroRated(FixtureSku::Sku004->value, 45.00),
            LineSpec::standard(FixtureSku::Sku005->value, 9.99),
        ], hasVatRelief: true);
        $orders[] = self::edge(EdgeCase::MultiQuantitySevenDp, $synced, \count($orders) + 1, lines: [
            new LineSpec(FixtureSku::Sku006->value, 8.33, 1.67, 20, 3),
        ]);
        $orders[] = self::edge(EdgeCase::DiscountedShipping, $synced, \count($orders) + 1, shipping: 3.00, originalShipping: 4.99);
        $orders[] = self::edge(EdgeCase::ShippingThirdDecimalFive, $synced, \count($orders) + 1, shipping: 4.125);
        $orders[] = self::edge(EdgeCase::SkuOverride, $synced, \count($orders) + 1, lines: [
            new LineSpec(self::LEGACY_SKU, 14.99, 3.00, 20, skuOverride: FixtureSku::Sku007->value),
        ]);
        $orders[] = self::edge(EdgeCase::MissingSku, ExpectedOutcome::FailedItemMissing, \count($orders) + 1, lines: [
            LineSpec::standard(self::MISSING_SKU, 6.50),
        ]);
        $orders[] = self::edge(EdgeCase::ZeroLines, ExpectedOutcome::FailedZeroLines, \count($orders) + 1, lines: [], shipping: 0.0);
        $orders[] = self::refunded(\count($orders) + 1);
        $orders[] = self::edge(EdgeCase::PartRefunded, $synced, \count($orders) + 1, status: OrderStatusType::PartRefunded, refunds: [5.00]);

        return $orders;
    }

    /**
     * @param list<LineSpec>|null $lines
     * @param list<float> $refunds
     */
    private static function edge(
        EdgeCase $case,
        ExpectedOutcome $outcome,
        int $slot,
        RawPaymentMethod $method = RawPaymentMethod::ShopWiredPayments,
        OrderStatusType $status = OrderStatusType::Processing,
        string $name = 'Jordan Blake',
        ?string $company = null,
        string $country = 'United Kingdom',
        ?array $lines = null,
        float $shipping = 4.99,
        ?float $originalShipping = null,
        bool $hasVatRelief = false,
        array $refunds = [],
    ): EdgeCaseOrder {
        return new EdgeCaseOrder($case, $outcome, new OrderSpec(
            externalId: SeedIds::ORDER_BASE + $slot * 2,
            reference: SeedIds::ORDER_REFERENCE_BASE + $slot,
            placedAt: OrderMonth::FIRST + $slot * self::HOUR,
            paymentMethod: $method,
            status: $status,
            customer: new Customer(
                id: SeedIds::CUSTOMER_BASE + $slot,
                name: $name,
                email: 'edge-case-' . $slot . '@example.com',
                addressLine1: $slot . ' Fixture Street',
                city: $country === 'United Kingdom' ? 'Leeds' : 'Dublin',
                postcode: $country === 'United Kingdom' ? 'LS1 4AP' : 'D02 X285',
                country: $country,
                company: $company,
            ),
            lines: $lines ?? [LineSpec::standard(FixtureSku::Sku001->value, 19.99), LineSpec::standard(FixtureSku::Sku002->value, 4.50, 2)],
            shippingNet: $shipping,
            originalShippingNet: $originalShipping ?? $shipping,
            hasVatRelief: $hasVatRelief,
            refunds: $refunds,
        ));
    }

    /**
     * The live row carries the higher external ID; its cancelled original sorts last in `orders_deduplicated`.
     */
    private static function edited(int $slot): EdgeCaseOrder
    {
        $live = self::edge(EdgeCase::Edited, ExpectedOutcome::Synced, $slot)->order;
        $original = new OrderSpec(
            externalId: $live->externalId - 1,
            reference: $live->reference,
            placedAt: $live->placedAt - self::HOUR,
            paymentMethod: $live->paymentMethod,
            status: OrderStatusType::Cancelled,
            customer: $live->customer,
            lines: [LineSpec::standard(FixtureSku::Sku001->value, 19.99)],
        );

        return new EdgeCaseOrder(EdgeCase::Edited, ExpectedOutcome::Synced, $live, $original);
    }

    /**
     * Two refund rows that together repay the whole order, so its total falls to 0.
     */
    private static function refunded(int $slot): EdgeCaseOrder
    {
        $lines = [LineSpec::standard(FixtureSku::Sku008->value, 24.00)];
        $baseline = OrderTotals::baseline($lines, 4.99);
        $first = \round($baseline / 2, 2);

        return self::edge(
            EdgeCase::Refunded,
            ExpectedOutcome::Synced,
            $slot,
            status: OrderStatusType::Refunded,
            lines: $lines,
            refunds: [$first, \round($baseline - $first, 3)],
        );
    }
}
