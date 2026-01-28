<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions\Data;

use App\Domain\Exceptions\Data\InvalidSkuException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidSkuException::class)]
final class InvalidSkuExceptionTest extends TestCase
{
    #[Test]
    public function empty_creates_exception_with_empty_reason(): void
    {
        $exception = InvalidSkuException::empty();

        self::assertSame('', $exception->value);
        self::assertSame('SKU cannot be empty', $exception->reason);
        self::assertStringContainsString('SKU cannot be empty', $exception->getMessage());
    }

    #[Test]
    public function too_long_creates_exception_with_length_details(): void
    {
        $exception = InvalidSkuException::tooLong('A-VERY-LONG-SKU-VALUE-HERE', 20);

        self::assertSame('A-VERY-LONG-SKU-VALUE-HERE', $exception->value);
        self::assertStringContainsString('exceeds maximum length of 20', $exception->reason);
        self::assertStringContainsString('got 26', $exception->reason);
    }

    #[Test]
    public function invalid_characters_creates_exception(): void
    {
        $exception = InvalidSkuException::invalidCharacters('SKU@123');

        self::assertSame('SKU@123', $exception->value);
        self::assertStringContainsString('alphanumeric characters', $exception->reason);
    }

    #[Test]
    public function missing_for_provided_type_creates_exception(): void
    {
        $exception = InvalidSkuException::missingForProvidedType();

        self::assertSame('', $exception->value);
        self::assertStringContainsString('newSku is required', $exception->reason);
    }
}
