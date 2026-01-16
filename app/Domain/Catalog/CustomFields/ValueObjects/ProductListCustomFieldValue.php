<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use Webmozart\Assert\Assert;

/**
 * Custom field value containing an array of ShopWired product IDs.
 *
 * Used for field type:
 * - ProductList: Array of ShopWired product IDs (integers)
 *
 * These are external product IDs from ShopWired, not internal UUIDs.
 */
final readonly class ProductListCustomFieldValue extends AbstractCustomFieldValue
{
    /**
     * @param list<int> $productIds ShopWired external product IDs
     */
    public function __construct(
        CustomFieldDefinition $definition,
        public array $productIds,
    ) {
        Assert::same(
            $definition->type,
            CustomFieldType::ProductList,
            "ProductListCustomFieldValue requires ProductList type, got '{$definition->type->value}'",
        );
        // @phpstan-ignore staticMethod.alreadyNarrowedType (runtime validation for external data)
        Assert::allInteger($productIds, 'ProductList values must all be integers');
        Assert::allGreaterThan($productIds, 0, 'ProductList IDs must be positive');

        parent::__construct($definition);
    }

    /**
     * @return list<int>
     */
    public function rawValue(): array
    {
        return $this->productIds;
    }

    public function isEmpty(): bool
    {
        return $this->productIds === [];
    }

    public function count(): int
    {
        return \count($this->productIds);
    }

    /**
     * Check if a specific product ID is in the list.
     */
    public function contains(int $productId): bool
    {
        return \in_array($productId, $this->productIds, true);
    }
}
