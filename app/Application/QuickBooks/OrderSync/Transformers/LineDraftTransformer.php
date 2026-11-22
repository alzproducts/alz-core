<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\QuickBooks\OrderSync\ValueObjects\NetLinePrice;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

/**
 * Tax-excluded lines: goods priced by `NetLinePrice`, and the post-discount shipping net at 2dp (COR-295 D8).
 */
final class LineDraftTransformer
{
    /**
     * @param non-empty-list<SyncOrderLineDTO> $lines
     *
     * @return non-empty-list<GoodsLineDraftDTO>
     *
     * @throws UnsupportedVatRateException
     */
    public static function goodsLines(SyncOrderDTO $order, array $lines): array
    {
        $drafts = [];

        foreach ($lines as $line) {
            $drafts[] = self::goodsLine($order, $line);
        }

        return $drafts;
    }

    /**
     * @throws UnsupportedVatRateException
     */
    public static function shippingLine(SyncOrderDTO $order): ?ShippingLineDraftDTO
    {
        if ($order->shippingTotalNet->compare(DecimalMoney::fromString('0')) === 0) {
            return null;
        }

        $rate = SupportedVatRate::fromTaxRate($order->shippingVatRate, $order->reference);

        return new ShippingLineDraftDTO(
            $order->shippingTotalNet->round(NetLinePrice::AMOUNT_SCALE),
            TaxKey::forShipping($rate, $order->delivery->country),
        );
    }

    /**
     * @throws UnsupportedVatRateException
     */
    private static function goodsLine(SyncOrderDTO $order, SyncOrderLineDTO $line): GoodsLineDraftDTO
    {
        $rate = SupportedVatRate::fromTaxRate($line->vatRate, $order->reference);
        $price = NetLinePrice::fromGross($line->unitPrice, $line->unitPriceVat, $rate, $line->quantity);

        return new GoodsLineDraftDTO(
            $line->sku,
            \str_replace(',', '', $line->title),
            $price->unitPrice,
            $line->quantity,
            $price->amount,
            TaxKey::forGoods($rate, $order->delivery->country),
        );
    }
}
