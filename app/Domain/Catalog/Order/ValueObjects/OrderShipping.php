<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order shipping method value object.
 *
 * Represents the shipping method applied to an order.
 *
 * @property float $chargeNet Shipping charge excluding VAT (NET value)
 */
final readonly class OrderShipping
{
    /**
     * @param int|null    $id       ShopWired shipping method ID
     * @param string|null $name     Shipping method display name; null when staff create an order without selecting a shipping method
     * @param float       $chargeNet Shipping charge excluding VAT (NET)
     * @param float       $vatRate  VAT rate percentage (e.g., 20.0 for 20%)
     */
    public function __construct(
        public ?int $id,
        public ?string $name,
        public float $chargeNet,
        public float $vatRate,
    ) {
        Assert::greaterThanEq($chargeNet, 0, 'Shipping charge cannot be negative');
        Assert::greaterThanEq($vatRate, 0, 'VAT rate cannot be negative');
    }
}
