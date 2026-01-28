<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\Enums;

use App\Domain\Inventory\Enums\SkuUpdateReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuUpdateReason::class)]
final class SkuUpdateReasonTest extends TestCase
{
    #[Test]
    #[DataProvider('labelProvider')]
    public function label_returns_human_readable_text(SkuUpdateReason $reason, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $reason->label());
    }

    /**
     * @return array<string, array{SkuUpdateReason, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'shorten long sku' => [SkuUpdateReason::ShortenLongSku, 'Shorten long SKU'],
            'fix sku mismatch' => [SkuUpdateReason::FixSkuMismatch, 'Fix SKU mismatch'],
            'standardize format' => [SkuUpdateReason::StandardizeFormat, 'Standardize format'],
            'merge products' => [SkuUpdateReason::MergeProducts, 'Merge products'],
            'other' => [SkuUpdateReason::Other, 'Other'],
        ];
    }
}
