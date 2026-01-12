<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Product variation value object.
 *
 * Represents a single variation attribute (e.g., "Colour" => "Ivory").
 * Immutable snapshot of the variation at time of purchase.
 */
final readonly class ProductVariation
{
    public function __construct(
        public string $name,
        public string $value,
    ) {
        Assert::notEmpty($name, 'Variation name cannot be empty');
    }

    /**
     * Create from array representation.
     *
     * @param array{name: string, value: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            value: $data['value'],
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array{name: string, value: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }
}
