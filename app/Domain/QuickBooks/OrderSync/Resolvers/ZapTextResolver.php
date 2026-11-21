<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Resolvers;

/**
 * The Zap's text transforms, replicated exactly, quirks included (Zap parity).
 */
final class ZapTextResolver
{
    private const int SHIP_VIA_MAX_LENGTH = 31;

    /**
     * Removes only the first `:` and the first `;`, as the Zap's single string replace does.
     */
    public static function removeInvalidChars(string $value): string
    {
        return self::removeFirst(self::removeFirst($value, ':'), ';');
    }

    /**
     * The upper-cased text between the first `REFERENCE` and the next (or the end), with only its first space removed.
     */
    public static function poNumber(string $comments): string
    {
        $upper = \mb_strtoupper($comments);

        if ($comments === '' || \str_contains($comments, '*>') || ! \str_contains($upper, 'REFERENCE')) {
            return '';
        }

        return self::removeFirst(\explode('REFERENCE', $upper)[1], ' ');
    }

    public static function shipVia(string $shippingMethod): string
    {
        $cleaned = self::removeInvalidChars($shippingMethod);
        $parenthesis = \mb_strpos($cleaned, '(');
        $method = $parenthesis === false ? $cleaned : \mb_substr($cleaned, 0, $parenthesis);

        return \mb_substr(\mb_trim($method), 0, self::SHIP_VIA_MAX_LENGTH);
    }

    public static function transactionId(string $transactionId): string
    {
        return \str_contains($transactionId, '{') ? '' : $transactionId;
    }

    private static function removeFirst(string $value, string $needle): string
    {
        $position = \mb_strpos($value, $needle);

        return $position === false
            ? $value
            : \mb_substr($value, 0, $position) . \mb_substr($value, $position + \mb_strlen($needle));
    }
}
