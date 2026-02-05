<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Filters\ValueObjects;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductFilter::class)]
final class ProductFilterTest extends TestCase
{
    // ========================================================================
    // Factory Helper
    // ========================================================================

    private static function createDefinition(
        int $id = 1,
        string $title = 'Size',
        int $optionNo = 1,
    ): FilterGroupDefinition {
        return new FilterGroupDefinition(
            id: $id,
            title: $title,
            optionNo: $optionNo,
            sortOrder: 0,
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_filter_with_values(): void
    {
        $definition = self::createDefinition();
        $filter = new ProductFilter($definition, ['Small', 'Medium', 'Large']);

        self::assertSame($definition, $filter->definition);
        self::assertSame(['Small', 'Medium', 'Large'], $filter->values);
    }

    #[Test]
    public function it_creates_filter_with_empty_values(): void
    {
        $definition = self::createDefinition();
        $filter = new ProductFilter($definition, []);

        self::assertSame([], $filter->values);
    }

    // ========================================================================
    // Accessor Methods
    // ========================================================================

    #[Test]
    public function title_returns_definition_title(): void
    {
        $definition = self::createDefinition(title: 'Colour');
        $filter = new ProductFilter($definition, ['Red']);

        self::assertSame('Colour', $filter->title());
    }

    #[Test]
    public function option_no_returns_definition_option_no(): void
    {
        $definition = self::createDefinition(optionNo: 42);
        $filter = new ProductFilter($definition, ['Value']);

        self::assertSame(42, $filter->optionNo());
    }

    // ========================================================================
    // hasValues()
    // ========================================================================

    #[Test]
    public function has_values_returns_true_when_values_exist(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Small']);

        self::assertTrue($filter->hasValues());
    }

    #[Test]
    public function has_values_returns_true_for_multiple_values(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Small', 'Medium']);

        self::assertTrue($filter->hasValues());
    }

    #[Test]
    public function has_values_returns_false_when_empty(): void
    {
        $filter = new ProductFilter(self::createDefinition(), []);

        self::assertFalse($filter->hasValues());
    }

    // ========================================================================
    // hasValue()
    // ========================================================================

    #[Test]
    public function has_value_returns_true_for_existing_value(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Small', 'Medium', 'Large']);

        self::assertTrue($filter->hasValue('Medium'));
    }

    #[Test]
    public function has_value_returns_false_for_missing_value(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Small', 'Medium']);

        self::assertFalse($filter->hasValue('Large'));
    }

    #[Test]
    public function has_value_is_case_sensitive(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Small']);

        self::assertTrue($filter->hasValue('Small'));
        self::assertFalse($filter->hasValue('small'));
        self::assertFalse($filter->hasValue('SMALL'));
    }

    #[Test]
    public function has_value_returns_false_for_empty_filter(): void
    {
        $filter = new ProductFilter(self::createDefinition(), []);

        self::assertFalse($filter->hasValue('Any'));
    }

    // ========================================================================
    // firstValue()
    // ========================================================================

    #[Test]
    public function first_value_returns_first_when_values_exist(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['First', 'Second', 'Third']);

        self::assertSame('First', $filter->firstValue());
    }

    #[Test]
    public function first_value_returns_only_value_when_single(): void
    {
        $filter = new ProductFilter(self::createDefinition(), ['Only']);

        self::assertSame('Only', $filter->firstValue());
    }

    #[Test]
    public function first_value_returns_null_when_empty(): void
    {
        $filter = new ProductFilter(self::createDefinition(), []);

        self::assertNull($filter->firstValue());
    }
}
