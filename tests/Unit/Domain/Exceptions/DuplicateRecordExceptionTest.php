<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests message formatting logic only.
 * Inheritance, previous exception support are standard PHP - verified by PHPStan.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class DuplicateRecordExceptionTest extends TestCase
{
    #[Test]
    public function it_formats_message_with_table_and_constraint(): void
    {
        $exception = new DuplicateRecordException(
            table: 'orders',
            constraint: 'orders_reference_unique',
        );

        $this->assertSame('Duplicate record constraint violation', $exception->getMessage());
        $this->assertSame(['table' => 'orders', 'constraint' => 'orders_reference_unique'], $exception->context());
    }
}
