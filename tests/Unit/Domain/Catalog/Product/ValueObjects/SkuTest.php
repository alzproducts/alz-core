<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sku::class)]
final class SkuTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromString() Tests - Validated Input
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_string_creates_sku_with_valid_input(): void
    {
        $sku = Sku::fromString('ABC-123_def');

        self::assertSame('ABC-123_def', $sku->value);
    }

    #[Test]
    public function from_string_trims_whitespace(): void
    {
        $sku = Sku::fromString('  TRIMMED-SKU  ');

        self::assertSame('TRIMMED-SKU', $sku->value);
    }

    #[Test]
    public function from_string_rejects_empty_string(): void
    {
        $this->expectException(InvalidSkuException::class);
        $this->expectExceptionMessage('Invalid SKU');

        Sku::fromString('');
    }

    #[Test]
    public function from_string_rejects_whitespace_only(): void
    {
        $this->expectException(InvalidSkuException::class);
        $this->expectExceptionMessage('Invalid SKU');

        Sku::fromString('   ');
    }

    #[Test]
    public function from_string_rejects_sku_exceeding_max_length(): void
    {
        $longSku = \str_repeat('A', 65);

        $this->expectException(InvalidSkuException::class);
        $this->expectExceptionMessage('Invalid SKU');

        Sku::fromString($longSku);
    }

    #[Test]
    public function from_string_accepts_sku_at_max_length(): void
    {
        $maxSku = \str_repeat('A', 64);

        $sku = Sku::fromString($maxSku);

        self::assertSame($maxSku, $sku->value);
    }

    #[Test]
    #[DataProvider('legacySkuProvider')]
    public function from_string_accepts_legacy_sku_formats(string $legacySku): void
    {
        // Legacy SKUs from Linnworks contain spaces, slashes, and other characters
        $sku = Sku::fromString($legacySku);

        self::assertSame($legacySku, $sku->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacySkuProvider(): array
    {
        return [
            'space in middle' => ['ABC 123'],
            'special character' => ['ABC@123'],
            'forward slash' => ['ABC/123'],
            'period' => ['ABC.123'],
            'hash' => ['ABC#123'],
            'real legacy sku' => ['FSKICK/150 X 800MM/10 - CORNFLOWER/SELF-ADHESIVE'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | fromTrusted() Tests - Unvalidated Input
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_trusted_creates_sku_without_validation(): void
    {
        // fromTrusted skips validation - used for database hydration
        $sku = Sku::fromTrusted('TRUSTED-SKU');

        self::assertSame('TRUSTED-SKU', $sku->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Equality and String Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_string_returns_value(): void
    {
        $sku = Sku::fromTrusted('STRING-SKU');

        self::assertSame('STRING-SKU', (string) $sku);
    }

    #[Test]
    public function equals_returns_true_for_matching_skus(): void
    {
        $sku1 = Sku::fromTrusted('SAME-SKU');
        $sku2 = Sku::fromTrusted('SAME-SKU');

        self::assertTrue($sku1->equals($sku2));
    }

    #[Test]
    public function equals_returns_false_for_different_skus(): void
    {
        $sku1 = Sku::fromTrusted('SKU-ONE');
        $sku2 = Sku::fromTrusted('SKU-TWO');

        self::assertFalse($sku1->equals($sku2));
    }

    #[Test]
    public function equals_is_case_sensitive(): void
    {
        $sku1 = Sku::fromTrusted('lowercase');
        $sku2 = Sku::fromTrusted('LOWERCASE');

        self::assertFalse($sku1->equals($sku2));
    }
}
