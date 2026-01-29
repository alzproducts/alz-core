<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\Guid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(Guid::class)]
final class GuidTest extends TestCase
{
    private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';
    private const string VALID_UUID_UPPERCASE = '550E8400-E29B-41D4-A716-446655440000';

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_constructs_with_valid_uuid(): void
    {
        $guid = new Guid(self::VALID_UUID);

        self::assertSame(self::VALID_UUID, $guid->value);
    }

    #[Test]
    public function it_accepts_uppercase_uuid(): void
    {
        $guid = new Guid(self::VALID_UUID_UPPERCASE);

        self::assertSame(self::VALID_UUID_UPPERCASE, $guid->value);
    }

    #[Test]
    public function it_rejects_invalid_uuid_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid GUID format');

        new Guid('not-a-uuid');
    }

    #[Test]
    public function it_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Guid('');
    }

    /*
    |--------------------------------------------------------------------------
    | fromTrusted() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_trusted_creates_guid(): void
    {
        $guid = Guid::fromTrusted(self::VALID_UUID);

        self::assertSame(self::VALID_UUID, $guid->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Equality Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function equals_returns_true_for_matching_guids(): void
    {
        $guid1 = new Guid(self::VALID_UUID);
        $guid2 = new Guid(self::VALID_UUID);

        self::assertTrue($guid1->equals($guid2));
    }

    #[Test]
    public function equals_returns_true_for_case_insensitive_match(): void
    {
        $guid1 = new Guid(self::VALID_UUID);
        $guid2 = new Guid(self::VALID_UUID_UPPERCASE);

        self::assertTrue($guid1->equals($guid2));
    }

    #[Test]
    public function equals_returns_false_for_different_guids(): void
    {
        $guid1 = new Guid(self::VALID_UUID);
        $guid2 = new Guid('12345678-1234-1234-1234-123456789012');

        self::assertFalse($guid1->equals($guid2));
    }
}
