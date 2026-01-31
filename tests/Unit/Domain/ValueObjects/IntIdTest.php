<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\IntId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

/**
 * Tests for IntId value object.
 */
#[CoversClass(IntId::class)]
final class IntIdTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_creates_int_id_from_positive_integer(): void
    {
        $id = IntId::from(12345);

        self::assertSame(12345, $id->value);
    }

    #[Test]
    public function from_trusted_creates_int_id(): void
    {
        $id = IntId::fromTrusted(99999);

        self::assertSame(99999, $id->value);
    }

    #[Test]
    public function from_accepts_value_of_one(): void
    {
        $id = IntId::from(1);

        self::assertSame(1, $id->value);
    }

    #[Test]
    public function from_accepts_large_value(): void
    {
        $id = IntId::from(PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, $id->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IntId must be a positive integer');

        IntId::from(0);
    }

    #[Test]
    public function from_rejects_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IntId must be a positive integer');

        IntId::from(-1);
    }

    #[Test]
    public function from_trusted_still_validates(): void
    {
        // fromTrusted should still run validation
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IntId must be a positive integer');

        IntId::fromTrusted(0);
    }

    /*
    |--------------------------------------------------------------------------
    | Equality Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function equals_returns_true_for_same_value(): void
    {
        $id1 = IntId::from(12345);
        $id2 = IntId::from(12345);

        self::assertTrue($id1->equals($id2));
    }

    #[Test]
    public function equals_returns_false_for_different_value(): void
    {
        $id1 = IntId::from(12345);
        $id2 = IntId::from(67890);

        self::assertFalse($id1->equals($id2));
    }

    #[Test]
    public function equals_is_symmetric(): void
    {
        $id1 = IntId::from(100);
        $id2 = IntId::from(100);

        self::assertTrue($id1->equals($id2));
        self::assertTrue($id2->equals($id1));
    }
}
