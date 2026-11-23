<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Infrastructure\QuickBooks\Mappers\QuickBooksDocumentPayload;

/**
 * Reads QBO rows as untyped properties: the SDK's PHPDoc types are not what its XML parse leaves.
 */
final readonly class QuickBooksSandboxRowParser
{
    public static function text(mixed $row, string $field): ?string
    {
        $value = self::field($row, $field);
        $value = \is_object($value) ? self::field($value, 'value') : $value;
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }

        $text = \mb_trim((string) $value);

        return $text === '' ? null : $text;
    }

    public static function isTrue(mixed $row, string $field): bool
    {
        return \in_array(self::field($row, $field), [true, 'true'], true);
    }

    public static function isFalse(mixed $row, string $field): bool
    {
        return \in_array(self::field($row, $field), [false, 'false'], true);
    }

    public static function field(mixed $row, string $field): mixed
    {
        return \is_object($row) ? \get_object_vars($row)[$field] ?? null : null;
    }

    public static function hasShippingLine(object $document): bool
    {
        return self::salesLine($document, QuickBooksDocumentPayload::SHIPPING_ITEM_ID) !== null;
    }

    /**
     * The goods line outweighs the shipping line, so a 20% taxable net that covers it must include it.
     */
    public static function hasStandardVat(object $document, string $itemId, string $taxCodeId): bool
    {
        $line = self::salesLine($document, $itemId);
        if ($line === null || self::text(self::field($line, 'SalesItemLineDetail'), 'TaxCodeRef') !== $taxCodeId) {
            return false;
        }

        $net = (float) self::text($line, 'Amount');

        return \array_any(
            self::rows(self::field(self::field($document, 'TxnTaxDetail'), 'TaxLine')),
            static function (mixed $taxLine) use ($net): bool {
                $detail = self::field($taxLine, 'TaxLineDetail');

                return (float) self::text($detail, 'TaxPercent') === 20.0 && (float) self::text($detail, 'NetAmountTaxable') >= $net;
            },
        );
    }

    private static function salesLine(object $document, string $itemId): mixed
    {
        return \array_find(
            self::rows(self::field($document, 'Line')),
            static fn(mixed $line): bool => self::text(self::field($line, 'SalesItemLineDetail'), 'ItemRef') === $itemId,
        );
    }

    /**
     * @return array<array-key, mixed> a single row parses as that row, not a list of one
     */
    private static function rows(mixed $value): array
    {
        return \is_array($value) ? $value : [$value];
    }
}
