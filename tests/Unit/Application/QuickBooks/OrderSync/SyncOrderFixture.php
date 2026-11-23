<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync;

use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;

final class SyncOrderFixture
{
    /**
     * @param list<SyncOrderLineDTO>|null $lines
     */
    public static function order(
        ?array $lines = null,
        RawPaymentMethod $paymentMethod = RawPaymentMethod::AdminOrder,
        ?OrderContactDTO $billing = null,
        ?OrderContactDTO $delivery = null,
        string $shippingTotalNet = '0.000000',
        ?float $shippingVatRate = 20.0,
        string $shippingMethod = 'Next Day Delivery',
        string $comments = '',
        string $transactionId = '',
        string $reference = '10042',
        string $createdAt = '2026-11-02 14:30:00',
    ): SyncOrderDTO {
        return new SyncOrderDTO(
            reference: $reference,
            createdAt: new DateTimeImmutable($createdAt),
            paymentMethod: $paymentMethod,
            billing: $billing ?? self::contact(),
            billingEmail: 'buyer@example.test',
            billingPhone: '01234 567890',
            delivery: $delivery ?? self::contact(name: 'Sam Carer', company: 'Care Home Ltd'),
            shippingMethod: $shippingMethod,
            shippingTotalNet: DecimalMoney::fromString($shippingTotalNet),
            shippingVatRate: self::taxRate($shippingVatRate),
            comments: $comments,
            transactionId: $transactionId,
            lines: $lines ?? [self::line()],
        );
    }

    public static function line(
        string $unitPrice = '8.333333',
        string $unitPriceVat = '1.666667',
        int $quantity = 1,
        ?float $vatRate = 20.0,
        string $title = 'Easy Grip Cutlery',
        string $sku = 'ALZ-001',
    ): SyncOrderLineDTO {
        return new SyncOrderLineDTO(
            Sku::fromString($sku),
            $title,
            $quantity,
            DecimalMoney::fromString($unitPrice),
            DecimalMoney::fromString($unitPriceVat),
            self::taxRate($vatRate),
        );
    }

    private static function taxRate(?float $percentage): ?TaxRate
    {
        return $percentage === null ? null : TaxRate::fromPercentage($percentage);
    }

    public static function contact(
        string $name = 'Alex Buyer',
        string $company = '',
        string $postcode = 'AB1 2CD',
        string $country = 'United Kingdom',
    ): OrderContactDTO {
        return new OrderContactDTO(
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
