<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ItemLookupResult::class)]
final class ItemLookupResultTest extends TestCase
{
    #[Test]
    public function it_matches_a_sku_ignoring_case(): void
    {
        $result = ItemLookupResult::fromMatches([Sku::fromString('abc-1')], [['name' => 'ABC-1', 'id' => '7']]);

        $this->assertSame('7', $result->itemIdFor(Sku::fromString('abc-1')));
        $this->assertSame('7', $result->itemIdFor(Sku::fromString('Abc-1')));
        $this->assertSame([], $result->missing);
    }

    #[Test]
    public function it_matches_an_all_digit_sku(): void
    {
        $result = ItemLookupResult::fromMatches([Sku::fromString('12345')], [['name' => '12345', 'id' => '9']]);

        $this->assertSame('9', $result->itemIdFor(Sku::fromString('12345')));
        $this->assertSame([], $result->missing);
    }

    #[Test]
    public function the_first_row_per_folded_name_wins(): void
    {
        $result = ItemLookupResult::fromMatches([Sku::fromString('ABC-1')], [
            ['name' => 'abc-1', 'id' => '1'],
            ['name' => 'ABC-1', 'id' => '2'],
        ]);

        $this->assertSame('1', $result->itemIdFor(Sku::fromString('ABC-1')));
    }

    #[Test]
    public function missing_skus_are_listed_once_per_folded_name(): void
    {
        $first = Sku::fromString('Gone-1');
        $result = ItemLookupResult::fromMatches([$first, Sku::fromString('GONE-1'), Sku::fromString('KEPT')], [['name' => 'kept', 'id' => '3']]);

        $this->assertSame([$first], $result->missing);
        $this->assertNull($result->itemIdFor($first));
    }
}
