<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowLineDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\ValueObjects\TaxRate;

/**
 * Turns a stored source order into the builder's input, or the first defect that keeps it from being one.
 */
final class SourceOrderTransformer
{
    public static function fromRow(SourceOrderRowDTO $row): SourceOrderDTO
    {
        $paymentMethod = RawPaymentMethod::tryFrom($row->paymentMethod ?? '');
        $defect = $paymentMethod === null ? SourceOrderDefect::PaymentMethodMissing : self::contentDefect($row);

        return new SourceOrderDTO(
            reference: $row->reference,
            externalId: $row->externalId,
            placedAt: $row->createdAt,
            status: $row->status,
            order: $paymentMethod === null || $defect !== null ? null : self::syncOrder($row, $paymentMethod),
            defect: $defect,
        );
    }

    private static function contentDefect(SourceOrderRowDTO $row): ?SourceOrderDefect
    {
        return match (true) {
            \array_any($row->lines, static fn(SourceOrderRowLineDTO $line): bool => \mb_trim($line->sku) === '') => SourceOrderDefect::SkuMissing,
            self::hasInvalidVatRate($row) => SourceOrderDefect::VatRateInvalid,
            \array_any($row->lines, static fn(SourceOrderRowLineDTO $line): bool => $line->quantity < 1) => SourceOrderDefect::QuantityInvalid,
            default => null,
        };
    }

    private static function hasInvalidVatRate(SourceOrderRowDTO $row): bool
    {
        // intentional: free shipping needs no rate, and an order with no lines is the builder's `zero_lines`.
        $shippingRate = $row->shippingVatRate ?? ($row->lines !== [] && self::chargesShipping($row) ? null : '0');

        return self::isInvalidRate($shippingRate)
            || \array_any($row->lines, static fn(SourceOrderRowLineDTO $line): bool => self::isInvalidRate($line->vatRate));
    }

    private static function chargesShipping(SourceOrderRowDTO $row): bool
    {
        return DecimalMoney::fromString($row->shippingTotalNet)->compare(DecimalMoney::fromString('0')) !== 0;
    }

    private static function isInvalidRate(?string $rate): bool
    {
        return $rate === null || (float) $rate < 0;
    }

    private static function syncOrder(SourceOrderRowDTO $row, RawPaymentMethod $paymentMethod): SyncOrderDTO
    {
        return new SyncOrderDTO(
            reference: (string) $row->reference,
            createdAt: $row->createdAt,
            paymentMethod: $paymentMethod,
            billing: $row->billing,
            billingEmail: $row->billingEmail,
            billingPhone: $row->billingPhone,
            delivery: $row->delivery,
            shippingMethod: $row->shippingMethod,
            shippingTotalNet: DecimalMoney::fromString($row->shippingTotalNet),
            shippingVatRate: self::taxRate($row->shippingVatRate),
            comments: $row->comments,
            transactionId: $row->transactionId,
            lines: \array_map(self::line(...), $row->lines),
        );
    }

    private static function line(SourceOrderRowLineDTO $line): SyncOrderLineDTO
    {
        return new SyncOrderLineDTO(
            Sku::fromTrusted($line->sku),
            $line->title,
            $line->quantity,
            DecimalMoney::fromString($line->unitPrice),
            DecimalMoney::fromString($line->unitPriceVat),
            self::taxRate($line->vatRate),
        );
    }

    private static function taxRate(?string $percentage): ?TaxRate
    {
        return $percentage === null ? null : TaxRate::fromPercentage((float) $percentage);
    }
}
