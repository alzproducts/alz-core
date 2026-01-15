<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Deterministic hash for order identity across analytics systems.
 *
 * Used to deduplicate orders between frontend JavaScript tracking and backend sync.
 * Algorithm must match frontend: SHA-256(reference + salt).
 *
 * @see Front-end: shopwired-theme/assets/js/utils/data/checkoutPageData.js
 */
final readonly class OrderAnalyticsHash
{
    private const int HASH_LENGTH = 64; // SHA-256 produces 64 hex characters @pest-mutate-ignore

    public function __construct(
        public string $value,
    ) {
        Assert::length($value, self::HASH_LENGTH, 'Order analytics hash must be 64 characters (SHA-256)');
        Assert::regex($value, '/^[a-f0-9]+$/', 'Order analytics hash must be lowercase hex');
    }

    /**
     * Generate hash from order reference and analytics salt.
     *
     * Algorithm: SHA-256(reference + salt)
     * MUST match frontend implementation for deduplication to work.
     */
    public static function fromReference(int $reference, string $salt): self
    {
        Assert::greaterThan($reference, 0, 'Order reference must be positive');
        Assert::notEmpty($salt, 'Analytics salt cannot be empty');

        return new self(\hash('sha256', $reference . $salt));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
