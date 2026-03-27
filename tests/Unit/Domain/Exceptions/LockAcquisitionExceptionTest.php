<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests message formatting logic only.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class LockAcquisitionExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_with_lock_name_and_timeout(): void
    {
        $exception = new LockAcquisitionException('sku-generation', 30);

        self::assertSame('sku-generation', $exception->lockName);
        self::assertSame(30, $exception->timeoutSeconds);
        self::assertSame('Failed to acquire lock', $exception->getMessage());
        self::assertSame(['lock_name' => 'sku-generation', 'timeout_seconds' => 30], $exception->context());
    }

    #[Test]
    public function it_preserves_previous_exception(): void
    {
        $previous = new RuntimeException('Redis connection failed');
        $exception = new LockAcquisitionException('my-lock', 10, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function it_formats_message_correctly_with_different_values(): void
    {
        $exception = new LockAcquisitionException('order-processing', 60);

        self::assertSame('Failed to acquire lock', $exception->getMessage());
        self::assertSame(['lock_name' => 'order-processing', 'timeout_seconds' => 60], $exception->context());
    }
}
