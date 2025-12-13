<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(DatabaseOperationFailedException::class)]
final class DatabaseOperationFailedExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_exception_with_operation_and_reason(): void
    {
        $exception = new DatabaseOperationFailedException(
            operation: 'insert',
            reason: 'Foreign key constraint failed',
        );

        $this->assertSame('insert', $exception->operation);
        $this->assertSame('Foreign key constraint failed', $exception->reason);
    }

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

    #[Test]
    public function it_extends_domain_exception(): void
    {
        $exception = new DatabaseOperationFailedException('delete', 'Row locked');

        $this->assertInstanceOf(DomainException::class, $exception);
    }

    #[Test]
    public function it_supports_previous_exception(): void
    {
        $previous = new RuntimeException('PDO error');
        $exception = new DatabaseOperationFailedException(
            operation: 'select',
            reason: 'Connection lost',
            previous: $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('PDO error', $exception->getPrevious()?->getMessage());
    }

    #[Test]
    public function it_allows_null_previous_exception(): void
    {
        $exception = new DatabaseOperationFailedException('insert', 'Constraint error');

        $this->assertNull($exception->getPrevious());
    }
}
