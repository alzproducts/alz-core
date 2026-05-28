<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access\ValueObjects;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiKeyToken::class)]
final class ApiKeyTokenTest extends TestCase
{
    #[Test]
    public function constructor_stores_value(): void
    {
        $token = new ApiKeyToken('sk_test_1234567890abcdef');

        self::assertSame('sk_test_1234567890abcdef', $token->value);
    }

    #[Test]
    public function constructor_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key token must not be empty');

        new ApiKeyToken('');
    }

    #[Test]
    #[DataProvider('shortTokenProvider')]
    public function masked_returns_all_asterisks_when_value_is_8_chars_or_fewer(string $value, string $expected): void
    {
        $token = new ApiKeyToken($value);

        self::assertSame($expected, $token->masked());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shortTokenProvider(): array
    {
        return [
            'one char' => ['a', '*'],
            'seven chars' => ['abcdefg', '*******'],
            'exactly eight chars' => ['abcdefgh', '********'],
        ];
    }

    #[Test]
    public function masked_returns_first_four_then_ellipsis_then_last_four_for_long_token(): void
    {
        $token = new ApiKeyToken('sk_test_1234567890abcdef');

        self::assertSame('sk_t...cdef', $token->masked());
    }

    #[Test]
    public function masked_handles_nine_char_token_boundary(): void
    {
        $token = new ApiKeyToken('abcdefghi');

        self::assertSame('abcd...fghi', $token->masked());
    }

    #[Test]
    public function masked_preserves_first_and_last_four_for_arbitrary_long_token(): void
    {
        $token = new ApiKeyToken('AKIAIOSFODNN7EXAMPLE');

        self::assertSame('AKIA...MPLE', $token->masked());
    }
}
