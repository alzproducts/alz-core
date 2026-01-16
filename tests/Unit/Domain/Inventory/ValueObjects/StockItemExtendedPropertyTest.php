<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItemExtendedProperty Value Object Unit Tests.
 *
 * Tests validation and helper methods for extended properties.
 */
#[CoversClass(StockItemExtendedProperty::class)]
final class StockItemExtendedPropertyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_extended_property_with_all_values(): void
    {
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'Color',
            value: 'Blue',
            type: 'Attribute',
        );

        $this->assertSame('row-123', $ep->rowId);
        $this->assertSame('Color', $ep->name);
        $this->assertSame('Blue', $ep->value);
        $this->assertSame('Attribute', $ep->type);
    }

    #[Test]
    public function it_accepts_empty_value(): void
    {
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'OptionalField',
            value: '',
            type: 'Attribute',
        );

        $this->assertSame('', $ep->value);
    }

    #[Test]
    public function it_accepts_empty_type(): void
    {
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'SomeName',
            value: 'SomeValue',
            type: '',
        );

        $this->assertSame('', $ep->type);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_row_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row ID cannot be empty');

        new StockItemExtendedProperty(
            rowId: '',
            name: 'Color',
            value: 'Blue',
            type: 'Attribute',
        );
    }

    #[Test]
    public function it_throws_for_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Property name cannot be empty');

        new StockItemExtendedProperty(
            rowId: 'row-123',
            name: '',
            value: 'Blue',
            type: 'Attribute',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | hasValue Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function hasValue_returns_true_for_non_empty_value(): void
    {
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'Color',
            value: 'Blue',
            type: 'Attribute',
        );

        $this->assertTrue($ep->hasValue());
    }

    #[Test]
    public function hasValue_returns_false_for_empty_value(): void
    {
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'Color',
            value: '',
            type: 'Attribute',
        );

        $this->assertFalse($ep->hasValue());
    }

    #[Test]
    public function hasValue_returns_true_for_whitespace_only_value(): void
    {
        // Whitespace is still a "value" - it's not empty string
        $ep = new StockItemExtendedProperty(
            rowId: 'row-123',
            name: 'Color',
            value: '   ',
            type: 'Attribute',
        );

        $this->assertTrue($ep->hasValue());
    }
}
