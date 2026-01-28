<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Tax treatment for monetary values.
 *
 * Required for all Money instances to ensure explicit documentation
 * of whether prices include/exclude VAT. Critical for systems like
 * ShopWired where costPrice must be tax-inclusive.
 */
enum TaxType: string
{
    /** Price includes VAT (gross price) */
    case Inclusive = 'inclusive';

    /** Price excludes VAT (net price) */
    case Exclusive = 'exclusive';

    /** No VAT applies (e.g., children's clothing, books) */
    case ZeroRated = 'zero_rated';

    /**
     * Check if tax conversion methods are meaningful for this type.
     *
     * ZeroRated prices are identical whether "gross" or "net".
     */
    public function hasTax(): bool
    {
        return $this !== self::ZeroRated;
    }
}
