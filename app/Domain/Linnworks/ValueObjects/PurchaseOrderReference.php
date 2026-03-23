<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\IntId;
use Random\RandomException;
use Webmozart\Assert\Assert;

/**
 * Linnworks purchase order reference number.
 *
 * Stored in the `ExternalInvoiceNumber` API field.
 * Two formats:
 * - Standard: `PO{10-digit-random}` (e.g., PO1234567890)
 * - Dropship: `PO{random}-{orderId}` (e.g., PO12345-42)
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderReference
{
    private function __construct(
        public string $value,
    ) {
        Assert::stringNotEmpty($value, 'Purchase order reference must not be empty');
    }

    /**
     * Generate a standard PO reference: PO{10-digit-random}.
     *
     * @throws RandomException When random number generation fails
     */
    public static function generate(): self
    {
        return new self('PO' . \mb_str_pad((string) \random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT));
    }

    /**
     * Generate a dropship PO reference: PO{random}-{orderId}.
     *
     * @throws RandomException When random number generation fails
     */
    public static function forDropship(IntId $orderId): self
    {
        return new self('PO' . \random_int(10_000, 99_999) . '-' . $orderId->value);
    }

    /**
     * Parse an existing reference string.
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
