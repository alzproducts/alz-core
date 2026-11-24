<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

/**
 * The two VAT-inclusive totals a dry run compares, each in whole pence.
 */
final class OrderTotalsResolver
{
    private const int PENCE = 2;

    /**
     * VAT is rounded per line, then summed.
     */
    public static function expectedTotal(DocumentCoreDTO $document): DecimalMoney
    {
        $lines = [...$document->goodsLines, ...($document->shippingLine === null ? [] : [$document->shippingLine])];

        return \array_reduce(
            $lines,
            static fn(DecimalMoney $sum, GoodsLineDraftDTO|ShippingLineDraftDTO $line): DecimalMoney => $sum
                ->plus($line->amount)
                ->plus($line->taxKey->vatRate()->vatOn($line->amount)),
            DecimalMoney::fromString('0.00'),
        )->round(self::PENCE);
    }

    /**
     * The order's original total, summed exactly and rounded once.
     */
    public static function shopwiredTotal(SourceOrderTotalsDTO $totals): DecimalMoney
    {
        return DecimalMoney::fromString($totals->subTotalNet)
            ->plus(DecimalMoney::fromString($totals->shippingTotalNet))
            ->plus(DecimalMoney::fromString($totals->taxValue ?? '0'))
            ->round(self::PENCE);
    }
}
