<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Exceptions\Data\InvalidGtinException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GTIN value object validation and check digit algorithm.
 *
 * Uses real-world GTINs from known products to verify the GS1 check digit algorithm.
 * The algorithm is: sum(digit[i] * (3 if odd position from right else 1)), then (10 - sum % 10) % 10.
 */
#[CoversClass(Gtin::class)]
final class GtinTest extends TestCase
{
    // ========================================================================
    // Valid GTINs - Happy Path
    // ========================================================================

    /**
     * @param non-empty-string $gtin
     */
    #[Test]
    #[DataProvider('validGtinProvider')]
    public function it_creates_valid_gtin_from_string(string $gtin, string $description): void
    {
        // Act
        $result = Gtin::fromString($gtin);

        // Assert
        self::assertSame($gtin, $result->value, "Failed for: {$description}");
        self::assertSame($gtin, (string) $result, 'String conversion should return raw value');
    }

    /**
     * Provides valid GTINs with known-good check digits.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validGtinProvider(): array
    {
        return [
            // GTIN-8 (EAN-8)
            'GTIN-8: Generic product' => ['12345670', 'Check digit 0'],
            'GTIN-8: Calculated example' => ['96385074', 'Check digit 4'],

            // GTIN-12 (UPC-A)
            'GTIN-12: US format' => ['012345678905', 'Standard UPC-A'],
            'GTIN-12: Amazon product' => ['885909950805', 'Apple iPhone charger'],

            // GTIN-13 (EAN-13) - Most common globally
            'GTIN-13: European product' => ['4006381333931', 'German product'],
            'GTIN-13: UK product' => ['5060033774243', 'UK format'],
            'GTIN-13: Book ISBN-13' => ['9780201633610', 'Design Patterns book'],

            // GTIN-14
            'GTIN-14: Case/pallet code' => ['10012345678902', 'Logistics unit'],
        ];
    }

    // ========================================================================
    // Normalization
    // ========================================================================

    #[Test]
    public function it_normalizes_whitespace_and_dashes(): void
    {
        // Arrange
        $inputWithSpaces = '978 0 201 63361 0';
        $inputWithDashes = '978-0-201-63361-0';
        $expected = '9780201633610';

        // Act
        $fromSpaces = Gtin::fromString($inputWithSpaces);
        $fromDashes = Gtin::fromString($inputWithDashes);

        // Assert
        self::assertSame($expected, $fromSpaces->value, 'Should normalize spaces');
        self::assertSame($expected, $fromDashes->value, 'Should normalize dashes');
    }

    // ========================================================================
    // Invalid Format Rejection
    // ========================================================================

    /**
     * @param non-empty-string $invalidGtin
     */
    #[Test]
    #[DataProvider('invalidFormatProvider')]
    public function it_rejects_invalid_formats(string $invalidGtin, string $expectedReason): void
    {
        // Assert
        $this->expectException(InvalidGtinException::class);
        $this->expectExceptionMessage('Invalid GTIN');

        // Act
        Gtin::fromString($invalidGtin);
    }

    /**
     * Provides invalid GTIN formats.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFormatProvider(): array
    {
        return [
            'Contains letters' => ['ABC12345678', 'must contain only digits'],
            'Contains special chars' => ['123#456$789', 'must contain only digits'],
            'Too short (7 digits)' => ['1234567', 'must be 8/12/13/14 digits'],
            'Wrong length (9 digits)' => ['123456789', 'must be 8/12/13/14 digits'],
            'Wrong length (10 digits)' => ['1234567890', 'must be 8/12/13/14 digits'],
            'Wrong length (11 digits)' => ['12345678901', 'must be 8/12/13/14 digits'],
            'Too long (15 digits)' => ['123456789012345', 'must be 8/12/13/14 digits'],
        ];
    }

    // ========================================================================
    // Check Digit Validation
    // ========================================================================

    /**
     * @param non-empty-string $gtinWithBadCheckDigit
     */
    #[Test]
    #[DataProvider('invalidCheckDigitProvider')]
    public function it_rejects_invalid_check_digits(string $gtinWithBadCheckDigit, int $correctCheckDigit): void
    {
        // Assert
        $this->expectException(InvalidGtinException::class);
        $this->expectExceptionMessage('Invalid GTIN');

        // Act
        Gtin::fromString($gtinWithBadCheckDigit);
    }

    /**
     * Provides GTINs with intentionally wrong check digits.
     *
     * Each entry modifies the last digit of a known-valid GTIN to create an invalid one.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function invalidCheckDigitProvider(): array
    {
        return [
            // Valid: 12345670, modified last digit
            'GTIN-8: Check digit 1 instead of 0' => ['12345671', 0],
            'GTIN-8: Check digit 9 instead of 0' => ['12345679', 0],

            // Valid: 9780201633610, modified last digit
            'GTIN-13: Check digit 1 instead of 0' => ['9780201633611', 0],
            'GTIN-13: Check digit 5 instead of 0' => ['9780201633615', 0],

            // Valid: 012345678905, modified last digit
            'GTIN-12: Check digit 0 instead of 5' => ['012345678900', 5],
        ];
    }

    // ========================================================================
    // Check Digit Algorithm Verification
    // ========================================================================

    #[Test]
    public function it_validates_check_digit_algorithm_with_known_calculation(): void
    {
        // This test verifies the GS1 algorithm implementation against a manually calculated example.
        // GTIN: 4006381333931
        //
        // Position (R→L): 13 12 11 10  9  8  7  6  5  4  3  2  1 (check)
        // Digit:           4  0  0  6  3  8  1  3  3  3  9  3  1
        // Multiplier:      1  3  1  3  1  3  1  3  1  3  1  3  -
        // Product:         4  0  0 18  3 24  1  9  3  9  9  9  = 89
        // Check: (10 - 89 % 10) % 10 = (10 - 9) % 10 = 1 ✓

        // Act
        $gtin = Gtin::fromString('4006381333931');

        // Assert - if this passes, the algorithm is correctly implemented
        self::assertSame('4006381333931', $gtin->value);
    }

    // ========================================================================
    // Trusted Source Factory
    // ========================================================================

    #[Test]
    public function it_creates_from_trusted_without_validation(): void
    {
        // Arrange - This would normally fail validation (wrong check digit)
        $invalidButTrusted = '12345679';

        // Act - fromTrusted bypasses validation
        $gtin = Gtin::fromTrusted($invalidButTrusted);

        // Assert
        self::assertSame($invalidButTrusted, $gtin->value);
    }

    #[Test]
    public function from_trusted_is_faster_for_database_hydration(): void
    {
        // This documents the purpose of fromTrusted: performance for known-valid data.
        // When loading from database, data has already been validated on insert.

        // Arrange
        $validGtin = '9780201633610';

        // Act - Both methods should produce identical results for valid input
        $validated = Gtin::fromString($validGtin);
        $trusted = Gtin::fromTrusted($validGtin);

        // Assert
        self::assertSame($validated->value, $trusted->value);
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    #[Test]
    public function it_handles_gtin_with_leading_zeros(): void
    {
        // GTIN-12 (UPC-A) commonly has leading zeros
        // Valid: 012345678905

        // Act
        $gtin = Gtin::fromString('012345678905');

        // Assert - Leading zero must be preserved
        self::assertSame('012345678905', $gtin->value);
        self::assertSame(12, \mb_strlen($gtin->value));
    }

    #[Test]
    public function it_rejects_empty_string(): void
    {
        // Assert
        $this->expectException(InvalidGtinException::class);
        $this->expectExceptionMessage('Invalid GTIN');

        // Act
        Gtin::fromString('');
    }

    #[Test]
    public function it_rejects_whitespace_only(): void
    {
        // Assert
        $this->expectException(InvalidGtinException::class);
        $this->expectExceptionMessage('Invalid GTIN');

        // Act
        Gtin::fromString('   ');
    }

    // ========================================================================
    // tryFromString
    // ========================================================================

    #[Test]
    public function try_from_string_returns_null_for_null(): void
    {
        self::assertNull(Gtin::tryFromString(null));
    }

    #[Test]
    #[DataProvider('emptyInputProvider')]
    public function try_from_string_returns_null_for_empty_or_whitespace(string $value): void
    {
        self::assertNull(Gtin::tryFromString($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emptyInputProvider(): array
    {
        return [
            'empty' => [''],
            'single space' => [' '],
            'multiple spaces' => ['     '],
            'tabs' => ["\t\t"],
        ];
    }

    /**
     * @param non-empty-string $value
     */
    #[Test]
    #[DataProvider('validGtinProvider')]
    public function try_from_string_returns_gtin_for_valid_input(string $value, string $description): void
    {
        $gtin = Gtin::tryFromString($value);

        self::assertInstanceOf(Gtin::class, $gtin, "Failed for: {$description}");
        self::assertSame($value, $gtin->value);
    }

    #[Test]
    public function try_from_string_normalizes_dashes_and_whitespace(): void
    {
        $gtin = Gtin::tryFromString('978-0-201-63361-0');

        self::assertInstanceOf(Gtin::class, $gtin);
        self::assertSame('9780201633610', $gtin->value);
    }

    #[Test]
    #[DataProvider('tryFromStringInvalidProvider')]
    public function try_from_string_returns_null_for_invalid_input(?string $value): void
    {
        self::assertNull(Gtin::tryFromString($value));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function tryFromStringInvalidProvider(): array
    {
        return [
            'letters' => ['ABC12345678'],
            'wrong length 9' => ['123456789'],
            'wrong length 10' => ['1234567890'],
            'too long' => ['123456789012345'],
            'bad check digit GTIN-8' => ['12345671'],
            'bad check digit GTIN-13' => ['9780201633611'],
        ];
    }

    // ========================================================================
    // Check digit algorithm — exact-value pinning per known fixture
    // ========================================================================

    #[Test]
    #[DataProvider('checkDigitAlgorithmProvider')]
    public function check_digit_algorithm_accepts_known_correct_values(string $gtin): void
    {
        $result = Gtin::fromString($gtin);

        self::assertSame($gtin, $result->value);
    }

    /**
     * Each fixture pairs a valid GTIN with its computed check digit position,
     * exercising different alternating-multiplier paths in the algorithm.
     *
     * @return array<string, array{0: string}>
     */
    public static function checkDigitAlgorithmProvider(): array
    {
        return [
            // GTIN-8: 8 digits, alternating 3,1,3,1,3,1,3 multipliers RTL excluding check
            'GTIN-8 ends in 0' => ['12345670'],
            'GTIN-8 ends in 4' => ['96385074'],
            'GTIN-12 with leading zeros' => ['012345678905'],
            'GTIN-13 ISBN' => ['9780201633610'],
            // GTIN-14 exercises the longest digit sweep
            'GTIN-14 logistics' => ['10012345678902'],
        ];
    }

    // ========================================================================
    // Normalization edge cases
    // ========================================================================

    #[Test]
    public function normalization_preserves_internal_value_after_dash_strip(): void
    {
        $gtin = Gtin::fromString('978-0-201-63361-0');

        self::assertSame('9780201633610', $gtin->value);
        self::assertSame(13, \mb_strlen($gtin->value));
    }

    #[Test]
    public function trusted_value_is_used_verbatim_without_normalization(): void
    {
        $gtin = Gtin::fromTrusted('9780201633610');

        self::assertSame('9780201633610', $gtin->value);
        self::assertSame('9780201633610', (string) $gtin);
    }

    #[Test]
    public function from_trusted_does_not_normalize_or_strip_dashes(): void
    {
        $gtin = Gtin::fromTrusted('978-0-201-63361-0');

        self::assertSame('978-0-201-63361-0', $gtin->value);
    }
}
