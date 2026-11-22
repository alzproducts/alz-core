<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use LogicException;

/**
 * The facade payload fields an invoice and a sales receipt share. Amounts are tax-excluded and QBO computes the VAT
 * from each line's tax code.
 */
final readonly class QuickBooksDocumentPayload
{
    /** QBO's built-in shipping item, present when the company's shipping preference is on. */
    public const string SHIPPING_ITEM_ID = 'SHIPPING_ITEM_ID';

    private const string SALES_ITEM_LINE = 'SalesItemLineDetail';

    /**
     * @return array<string, mixed>
     */
    public static function core(DocumentCoreDTO $core, ResolvedDocumentRefsDTO $refs): array
    {
        $lines = \array_map(static fn(GoodsLineDraftDTO $line): array => self::goodsLine($line, $refs), $core->goodsLines);
        if ($core->shippingLine !== null) {
            $lines[] = self::shippingLine($core->shippingLine, $refs);
        }

        $email = self::nonBlank($core->billEmail);

        return self::present([
            'CustomerRef' => self::ref($refs->customerId),
            'DocNumber' => $core->docNumber,
            'TxnDate' => $core->txnDate->format('Y-m-d'),
            'BillEmail' => $email === null ? null : ['Address' => $email],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'DepartmentRef' => self::ref(self::fixedRef($refs, FixedRefKey::Department)),
            'Line' => $lines,
        ]);
    }

    /**
     * @template T
     *
     * @param array<string, T|null> $fields
     *
     * @return array<string, T>
     */
    public static function present(array $fields): array
    {
        return \array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array{value: string}
     */
    public static function ref(string $id): array
    {
        return ['value' => $id];
    }

    /**
     * @return array{DefinitionId: string, Type: string, StringValue: string}|null null when the value is blank
     */
    public static function stringField(?string $definitionId, string $value): ?array
    {
        $value = self::nonBlank($value);

        return $value === null ? null : ['DefinitionId' => self::required($definitionId), 'Type' => 'StringType', 'StringValue' => $value];
    }

    public static function fixedRef(ResolvedDocumentRefsDTO $refs, FixedRefKey $key): string
    {
        return self::required($refs->fixedRefIdFor($key));
    }

    /**
     * A ref the draft names but the refs lack: `PostableDocumentDTO` asserts none is missing, so this never fires.
     */
    public static function required(?string $id): string
    {
        return $id ?? throw new LogicException('A postable document lacks a ref its draft names');
    }

    public static function nonBlank(string $value): ?string
    {
        $trimmed = \mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function goodsLine(GoodsLineDraftDTO $line, ResolvedDocumentRefsDTO $refs): array
    {
        return self::present([
            'Amount' => $line->amount->toString(),
            'Description' => self::nonBlank($line->description),
            'DetailType' => self::SALES_ITEM_LINE,
            self::SALES_ITEM_LINE => [
                'ItemRef' => self::ref(self::required($refs->itemIdFor($line->itemSku))),
                'UnitPrice' => $line->unitPrice->toString(),
                'Qty' => $line->quantity,
                'TaxCodeRef' => self::ref(self::taxCode($refs, $line->taxKey)),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function shippingLine(ShippingLineDraftDTO $line, ResolvedDocumentRefsDTO $refs): array
    {
        return [
            'Amount' => $line->amount->toString(),
            'DetailType' => self::SALES_ITEM_LINE,
            self::SALES_ITEM_LINE => [
                'ItemRef' => self::ref(self::SHIPPING_ITEM_ID),
                'TaxCodeRef' => self::ref(self::taxCode($refs, $line->taxKey)),
            ],
        ];
    }

    private static function taxCode(ResolvedDocumentRefsDTO $refs, TaxKey $key): string
    {
        return self::required($refs->taxCodeIdFor($key));
    }
}
