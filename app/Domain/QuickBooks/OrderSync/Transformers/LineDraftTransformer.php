<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Transformers;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\QuickBooks\OrderSync\ValueObjects\GoodsLineDraft;
use App\Domain\QuickBooks\OrderSync\ValueObjects\ShippingLineDraft;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrderLine;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

/**
 * Tax-excluded lines under COR-295 D16 (7dp net unit price, 2dp amount) and D8 (post-discount shipping net, 2dp).
 */
final class LineDraftTransformer
{
    private const int UNIT_PRICE_SCALE = 7;
    private const int AMOUNT_SCALE = 2;

    /**
     * @param non-empty-list<SyncOrderLine> $lines
     *
     * @return non-empty-list<GoodsLineDraft>
     *
     * @throws UnsupportedVatRateException
     */
    public static function goodsLines(SyncOrder $order, array $lines): array
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
    public static function shippingLine(SyncOrder $order): ?ShippingLineDraft
    {
        if ($order->shippingTotalNet->compare(DecimalMoney::fromString('0')) === 0) {
            return null;
        }

        $rate = SupportedVatRate::fromRate($order->shippingVatRate, $order->reference);

        return new ShippingLineDraft(
            $order->shippingTotalNet->round(self::AMOUNT_SCALE),
            TaxKey::forShipping($rate, $order->delivery->country),
        );
    }

    /**
     * @throws UnsupportedVatRateException
     */
    private static function goodsLine(SyncOrder $order, SyncOrderLine $line): GoodsLineDraft
    {
        $rate = SupportedVatRate::fromRate($line->vatRate, $order->reference);
        $unitPrice = $line->unitPrice
            ->plus($line->unitPriceVat)
            ->dividedBy($rate->multiplier()->toString())
            ->round(self::UNIT_PRICE_SCALE);

        return new GoodsLineDraft(
            $line->sku,
            \str_replace(',', '', $line->title),
            $unitPrice,
            $line->quantity,
            $unitPrice->times($line->quantity)->round(self::AMOUNT_SCALE),
            TaxKey::forGoods($rate, $order->delivery->country),
        );
    }
}
