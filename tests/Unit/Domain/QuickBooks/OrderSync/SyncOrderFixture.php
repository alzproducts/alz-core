<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\ValueObjects\OrderContact;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrderLine;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use DateTimeImmutable;

final class SyncOrderFixture
{
    /**
     * @param list<SyncOrderLine>|null $lines
     */
    public static function order(
        ?array $lines = null,
        RawPaymentMethod $paymentMethod = RawPaymentMethod::AdminOrder,
        bool $cancelled = false,
        ?OrderContact $billing = null,
        ?OrderContact $delivery = null,
        string $shippingTotalNet = '0.000000',
        ?string $shippingVatRate = '20.0',
        string $shippingMethod = 'Next Day Delivery',
        string $comments = '',
        string $transactionId = '',
    ): SyncOrder {
        return new SyncOrder(
            reference: '10042',
            createdAt: new DateTimeImmutable('2026-11-02 14:30:00'),
            cancelled: $cancelled,
            paymentMethod: $paymentMethod,
            billing: $billing ?? self::contact(),
            billingEmail: 'buyer@example.test',
            billingPhone: '01234 567890',
            delivery: $delivery ?? self::contact(name: 'Sam Carer', company: 'Care Home Ltd'),
            shippingMethod: $shippingMethod,
            shippingTotalNet: DecimalMoney::fromString($shippingTotalNet),
            shippingVatRate: $shippingVatRate,
            comments: $comments,
            transactionId: $transactionId,
            lines: $lines ?? [self::line()],
        );
    }

    public static function line(
        string $unitPrice = '8.333333',
        string $unitPriceVat = '1.666667',
        int $quantity = 1,
        ?string $vatRate = '20.0',
        string $title = 'Easy Grip Cutlery',
        string $sku = 'ALZ-001',
    ): SyncOrderLine {
        return new SyncOrderLine(
            Sku::fromString($sku),
            $title,
            $quantity,
            DecimalMoney::fromString($unitPrice),
            DecimalMoney::fromString($unitPriceVat),
            $vatRate,
        );
    }

    public static function contact(
        string $name = 'Alex Buyer',
        string $company = '',
        string $postcode = 'AB1 2CD',
        string $country = 'United Kingdom',
    ): OrderContact {
        return new OrderContact(
            name: $name,
            company: $company,
            line1: '1 High Street',
            line2: 'Flat 2',
            line3: '',
            city: 'Leeds',
            postcode: $postcode,
            country: $country,
        );
    }
}
