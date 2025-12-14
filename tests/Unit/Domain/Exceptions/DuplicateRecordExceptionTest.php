<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\DuplicateRecordException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests for DuplicateRecordException.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class DuplicateRecordExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_exception_with_table_and_constraint(): void
    {
        $exception = new DuplicateRecordException(
            table: 'users',
            constraint: 'users_email_unique',
        );

        $this->assertSame('users', $exception->table);
        $this->assertSame('users_email_unique', $exception->constraint);
    }

    #[Test]
    public function it_formats_message_with_table_and_constraint(): void
    {
        $exception = new DuplicateRecordException(
            table: 'orders',
            constraint: 'orders_reference_unique',
        );

        $this->assertSame(
            "Duplicate record in 'orders' (constraint: orders_reference_unique)",
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_extends_domain_exception(): void
    {
        $exception = new DuplicateRecordException('products', 'sku_unique');

        $this->assertInstanceOf(DomainException::class, $exception);
    }

    #[Test]
    public function it_supports_previous_exception(): void
    {
        $previous = new RuntimeException('Unique constraint violation');
        $exception = new DuplicateRecordException(
            table: 'inventory',
            constraint: 'inventory_sku_location_unique',
            previous: $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('Unique constraint violation', $exception->getPrevious()?->getMessage());
    }

    #[Test]
    public function it_allows_null_previous_exception(): void
    {
        $exception = new DuplicateRecordException('customers', 'email_unique');

        $this->assertNull($exception->getPrevious());
    }
}
