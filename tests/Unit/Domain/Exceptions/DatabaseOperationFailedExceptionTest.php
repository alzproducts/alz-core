<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\DatabaseOperationFailedException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests message formatting logic only.
 * Inheritance, previous exception support are standard PHP - verified by PHPStan.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class DatabaseOperationFailedExceptionTest extends TestCase
{
    #[Test]
    public function it_formats_message_with_operation_and_reason(): void
    {
        $exception = new DatabaseOperationFailedException(
            operation: 'update',
            reason: 'Column not found',
        );

        $this->assertSame(
            'Database update failed: Column not found',
            $exception->getMessage(),
        );
    }
}
