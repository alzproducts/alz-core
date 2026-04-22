<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductListCustomFieldValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductListCustomFieldValue::class)]
final class ProductListCustomFieldValueTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_product_list(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [101, 102, 103]);

        self::assertSame([101, 102, 103], $value->productIds);
        self::assertSame([101, 102, 103], $value->rawValue());
    }

    #[Test]
    public function it_allows_empty_array(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertSame([], $value->productIds);
        self::assertSame([], $value->rawValue());
    }

    #[Test]
    public function it_allows_single_product(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [999]);

        self::assertSame([999], $value->productIds);
    }

    // ========================================================================
    // isEmpty Helper
    // ========================================================================

    #[Test]
    public function is_empty_returns_true_for_empty_array(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertTrue($value->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_for_non_empty_array(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [1]);

        self::assertFalse($value->isEmpty());
    }

    // ========================================================================
    // count Helper
    // ========================================================================

    #[Test]
    public function count_returns_zero_for_empty_array(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertSame(0, $value->count());
    }

    #[Test]
    public function count_returns_correct_value(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [1, 2, 3, 4, 5, 6, 7]);

        self::assertSame(7, $value->count());
    }

    // ========================================================================
    // contains Helper
    // ========================================================================

    #[Test]
    public function contains_returns_true_for_existing_id(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [100, 200, 300]);

        self::assertTrue($value->contains(200));
    }

    #[Test]
    public function contains_returns_false_for_missing_id(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [100, 200, 300]);

        self::assertFalse($value->contains(999));
    }

    #[Test]
    public function contains_returns_false_for_empty_list(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertFalse($value->contains(1));
    }

    #[Test]
    public function contains_uses_strict_comparison(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, [100, 200, 300]);

        self::assertTrue($value->contains(100));
    }

    // ========================================================================
    // Inherited Methods from Abstract
    // ========================================================================

    #[Test]
    public function name_returns_definition_name(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertSame('related_products', $value->name());
    }

    #[Test]
    public function label_returns_definition_label(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertSame('Related Products', $value->label());
    }

    #[Test]
    public function type_returns_product_list(): void
    {
        $definition = $this->createProductListDefinition();
        $value = new ProductListCustomFieldValue($definition, []);

        self::assertSame(CustomFieldType::ProductList, $value->type());
    }

    // ========================================================================
    // Validation - Type Mismatch
    // ========================================================================

    #[Test]
    public function it_rejects_text_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'notes',
            type: CustomFieldType::Text,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ProductListCustomFieldValue requires ProductList type, got 'text'");

        new ProductListCustomFieldValue($definition, []);
    }

    #[Test]
    public function it_rejects_toggle_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'is_featured',
            type: CustomFieldType::Toggle,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ProductListCustomFieldValue requires ProductList type, got 'toggle'");

        new ProductListCustomFieldValue($definition, []);
    }

    #[Test]
    public function it_rejects_value_list_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'tags',
            type: CustomFieldType::ValueList,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ProductListCustomFieldValue requires ProductList type, got 'value_list'");

        new ProductListCustomFieldValue($definition, []);
    }

    #[Test]
    public function it_rejects_date_type(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'release_date',
            type: CustomFieldType::Date,
            label: null,
            itemType: CustomFieldItemType::Product,
            sortOrder: null,
            allowedValues: null,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ProductListCustomFieldValue requires ProductList type, got 'date'");

        new ProductListCustomFieldValue($definition, []);
    }

    // ========================================================================
    // Validation - Array Content
    // ========================================================================

    #[Test]
    public function it_rejects_array_with_non_integer_values(): void
    {
        $definition = $this->createProductListDefinition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductList values must all be integers');

        // @phpstan-ignore argument.type (testing runtime validation)
        new ProductListCustomFieldValue($definition, [1, 'two', 3]);
    }

    #[Test]
    public function it_rejects_non_positive_ids(): void
    {
        $definition = $this->createProductListDefinition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductList IDs must be positive');

        new ProductListCustomFieldValue($definition, [1, 0, 3]);
    }

    #[Test]
    public function it_rejects_negative_ids(): void
    {
        $definition = $this->createProductListDefinition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductList IDs must be positive');

        new ProductListCustomFieldValue($definition, [1, -5, 3]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createProductListDefinition(): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'related_products',
            type: CustomFieldType::ProductList,
            label: 'Related Products',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
    }

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition($base, null, null);
    }
}
