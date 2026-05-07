<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\Resolvers\OneBasedIndexLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OneBasedIndexLookup::class)]
final class OneBasedIndexLookupTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Null / Out-of-Range Index
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_index_is_null(): void
    {
        self::assertNull(OneBasedIndexLookup::at(null, ['a', 'b', 'c']));
    }

    #[Test]
    #[DataProvider('belowOneIndexProvider')]
    public function it_returns_null_for_indexes_below_one(int $index): void
    {
        self::assertNull(OneBasedIndexLookup::at($index, ['a', 'b', 'c']));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function belowOneIndexProvider(): array
    {
        return [
            'zero' => [0],
            'negative one' => [-1],
            'large negative' => [-99],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Valid 1-Based Lookup
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_first_element_for_index_one(): void
    {
        self::assertSame('a', OneBasedIndexLookup::at(1, ['a', 'b', 'c']));
    }

    #[Test]
    public function it_returns_second_element_for_index_two(): void
    {
        self::assertSame('b', OneBasedIndexLookup::at(2, ['a', 'b', 'c']));
    }

    #[Test]
    public function it_returns_last_element_for_index_equal_to_array_length(): void
    {
        self::assertSame('c', OneBasedIndexLookup::at(3, ['a', 'b', 'c']));
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary / Out-of-Bounds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_index_exceeds_array_length(): void
    {
        self::assertNull(OneBasedIndexLookup::at(4, ['a', 'b', 'c']));
    }

    #[Test]
    public function it_returns_null_for_empty_array(): void
    {
        self::assertNull(OneBasedIndexLookup::at(1, []));
    }

    #[Test]
    public function it_works_with_struct_array_elements(): void
    {
        $items = [
            ['id' => 10, 'name' => 'first'],
            ['id' => 20, 'name' => 'second'],
        ];

        self::assertSame(['id' => 20, 'name' => 'second'], OneBasedIndexLookup::at(2, $items));
    }
}
