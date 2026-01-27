<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use ValueError;
use Webmozart\Assert\Assert;

/**
 * Command to set free delivery type on a product.
 *
 * This is a pure business request object - it contains the intent
 * to update a product's free delivery designation. The identifier
 * can be either a ShopWired product ID (int) or a SKU (string).
 *
 * Resolution of SKU to product ID happens in Infrastructure layer.
 */
final readonly class SetFreeDeliveryCommand
{
    /**
     * @param string|int $identifier Product identifier (SKU string or product ID int)
     * @param FreeDeliveryType $freeDeliveryType The delivery type to set
     */
    public function __construct(
        public string|int $identifier,
        public FreeDeliveryType $freeDeliveryType,
    ) {
        if (\is_string($identifier)) {
            Assert::notEmpty(\mb_trim($identifier), 'SKU identifier cannot be empty');
        } else {
            Assert::greaterThan($identifier, 0, 'Product ID must be positive');
        }
    }

    /**
     * Create command from raw input (useful for HTTP/console parsing).
     *
     * @param string|int $identifier Product identifier
     * @param string $type Free delivery type string
     *
     * @throws ValueError When type is invalid
     */
    public static function fromInput(string|int $identifier, string $type): self
    {
        return new self($identifier, FreeDeliveryType::fromString($type));
    }

    /**
     * Check if identifier is a SKU (string) vs product ID (int).
     */
    public function isSkuIdentifier(): bool
    {
        return \is_string($this->identifier);
    }
}
