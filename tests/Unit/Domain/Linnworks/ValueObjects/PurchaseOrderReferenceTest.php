<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Linnworks\ValueObjects;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PurchaseOrderReference Value Object Unit Tests.
 *
 * Tests generation formats and validation rules for PO reference numbers.
 */
#[CoversClass(PurchaseOrderReference::class)]
final class PurchaseOrderReferenceTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | generate()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function generate_produces_po_prefix_with_10_digit_number(): void
    {
        $reference = PurchaseOrderReference::generate();

        $this->assertMatchesRegularExpression('/^PO\d{10}$/', $reference->value);
    }

    #[Test]
    public function generate_produces_different_values_on_repeated_calls(): void
    {
        $references = [];
        for ($i = 0; $i < 5; $i++) {
            $references[] = PurchaseOrderReference::generate()->value;
        }

        // At least some should differ (collision probability is ~1 in 10 billion)
        $this->assertGreaterThan(1, \count(\array_unique($references)));
    }

    /*
    |--------------------------------------------------------------------------
    | forDropship()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function for_dropship_produces_po_prefix_random_dash_order_id_format(): void
    {
        $reference = PurchaseOrderReference::forDropship('ORD-12345');

        $this->assertMatchesRegularExpression('/^PO\d{5}-ORD-12345$/', $reference->value);
    }

    #[Test]
    public function for_dropship_embeds_order_id_after_dash(): void
    {
        $reference = PurchaseOrderReference::forDropship('MY-ORDER-99');

        $this->assertStringContainsString('-MY-ORDER-99', $reference->value);
        $this->assertStringStartsWith('PO', $reference->value);
    }

    #[Test]
    public function for_dropship_throws_on_empty_order_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order ID must not be empty for dropship reference');

        PurchaseOrderReference::forDropship('');
    }

    /*
    |--------------------------------------------------------------------------
    | fromString()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_string_parses_existing_reference(): void
    {
        $reference = PurchaseOrderReference::fromString('PO1234567890');

        $this->assertSame('PO1234567890', $reference->value);
    }

    #[Test]
    public function from_string_preserves_any_valid_string_as_value(): void
    {
        $reference = PurchaseOrderReference::fromString('PO12345-ORD-789');

        $this->assertSame('PO12345-ORD-789', $reference->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Constructor Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_string_throws_on_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Purchase order reference must not be empty');

        PurchaseOrderReference::fromString('');
    }
}
