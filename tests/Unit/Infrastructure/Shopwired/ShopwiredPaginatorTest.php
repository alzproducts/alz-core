<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShopwiredPaginator Unit Tests.
 *
 * Tests the static pagination utility for fetching all pages from ShopWired API.
 * Covers fetchAll() callback-based pagination and calculatePageCount() utility.
 */
#[CoversClass(ShopwiredPaginator::class)]
final class ShopwiredPaginatorTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fetchAll() Tests - Basic Scenarios
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function fetch_all_returns_empty_array_when_first_page_is_empty(): void
    {
        $params = new ShopwiredQueryParams(count: 10, offset: 0);
        $fetchPage = static fn(ShopwiredQueryParams $p): array => [];

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame([], $result);
    }

    #[Test]
    public function fetch_all_returns_items_from_single_incomplete_page(): void
    {
        $params = new ShopwiredQueryParams(count: 10, offset: 0);
        $expectedItems = ['item1', 'item2', 'item3'];
        $fetchPage = static fn(ShopwiredQueryParams $p): array => $expectedItems;

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame($expectedItems, $result);
    }

    #[Test]
    public function fetch_all_fetches_second_page_when_first_is_exactly_full(): void
    {
        $params = new ShopwiredQueryParams(count: 2, offset: 0);
        $page1Items = ['a', 'b'];
        $page2Items = [];

        $callCount = 0;
        $fetchPage = static function (ShopwiredQueryParams $p) use (&$callCount, $page1Items, $page2Items): array {
            $callCount++;

            return $callCount === 1 ? $page1Items : $page2Items;
        };

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame($page1Items, $result);
        $this->assertSame(2, $callCount);
    }

    /*
    |--------------------------------------------------------------------------
    | fetchAll() Tests - Multiple Pages
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function fetch_all_accumulates_items_across_multiple_pages(): void
    {
        $params = new ShopwiredQueryParams(count: 2, offset: 0);
        $pageData = [
            ['a', 'b'],
            ['c', 'd'],
            ['e'],
        ];

        $callCount = 0;
        $fetchPage = static function (ShopwiredQueryParams $p) use (&$callCount, $pageData): array {
            return $pageData[$callCount++] ?? [];
        };

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $result);
        $this->assertSame(3, $callCount);
    }

    #[Test]
    public function fetch_all_preserves_item_order(): void
    {
        $params = new ShopwiredQueryParams(count: 3, offset: 0);
        $pageData = [
            [1, 2, 3],
            [4, 5, 6],
            [7],
        ];

        $callCount = 0;
        $fetchPage = static function (ShopwiredQueryParams $p) use (&$callCount, $pageData): array {
            return $pageData[$callCount++] ?? [];
        };

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | fetchAll() Tests - Callback Parameter Verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function fetch_all_advances_offset_correctly_on_each_iteration(): void
    {
        $params = new ShopwiredQueryParams(count: 3, offset: 0);
        $receivedOffsets = [];

        $fetchPage = static function (ShopwiredQueryParams $p) use (&$receivedOffsets): array {
            $receivedOffsets[] = $p->offset;

            // Return full page for first two, incomplete for third
            return \count($receivedOffsets) < 3 ? ['a', 'b', 'c'] : ['x'];
        };

        ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame([0, 3, 6], $receivedOffsets);
    }

    #[Test]
    public function fetch_all_preserves_count_on_each_iteration(): void
    {
        $params = new ShopwiredQueryParams(count: 5, offset: 0);
        $receivedCounts = [];

        $fetchPage = static function (ShopwiredQueryParams $p) use (&$receivedCounts): array {
            $receivedCounts[] = $p->count;

            return \count($receivedCounts) < 3 ? ['a', 'b', 'c', 'd', 'e'] : ['x'];
        };

        ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame([5, 5, 5], $receivedCounts);
    }

    #[Test]
    public function fetch_all_preserves_embeds_on_each_iteration(): void
    {
        $params = new ShopwiredQueryParams(count: 2, offset: 0, embeds: ['parents']);
        $receivedEmbeds = [];

        $fetchPage = static function (ShopwiredQueryParams $p) use (&$receivedEmbeds): array {
            $receivedEmbeds[] = $p->embeds;

            return \count($receivedEmbeds) < 2 ? ['a', 'b'] : ['x'];
        };

        ShopwiredPaginator::fetchAll($params, $fetchPage);

        $this->assertSame([['parents'], ['parents']], $receivedEmbeds);
    }

    /*
    |--------------------------------------------------------------------------
    | fetchAll() Tests - knownTotal Parameter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function fetch_all_stops_at_known_total_exactly(): void
    {
        $params = new ShopwiredQueryParams(count: 2, offset: 0);
        $knownTotal = 4;

        $callCount = 0;
        $fetchPage = static function (ShopwiredQueryParams $p) use (&$callCount): array {
            $callCount++;

            return ['a', 'b']; // Always return full page
        };

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage, $knownTotal);

        $this->assertSame(['a', 'b', 'a', 'b'], $result);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function fetch_all_with_known_total_does_not_truncate_last_page(): void
    {
        $params = new ShopwiredQueryParams(count: 3, offset: 0);
        $knownTotal = 5;

        $callCount = 0;
        $fetchPage = static function (ShopwiredQueryParams $p) use (&$callCount): array {
            $callCount++;

            return $callCount === 1 ? ['a', 'b', 'c'] : ['d', 'e', 'f'];
        };

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage, $knownTotal);

        // knownTotal=5, but we get 6 items (3+3) because it doesn't truncate
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f'], $result);
        $this->assertCount(6, $result);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function fetch_all_prefers_incomplete_page_over_known_total(): void
    {
        $params = new ShopwiredQueryParams(count: 10, offset: 0);
        $knownTotal = 100; // High known total

        $fetchPage = static fn(ShopwiredQueryParams $p): array => ['a', 'b']; // Only 2 items

        $result = ShopwiredPaginator::fetchAll($params, $fetchPage, $knownTotal);

        // Stops after first page because 2 < 10 (incomplete page)
        $this->assertSame(['a', 'b'], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | calculatePageCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function calculate_page_count_with_exact_division(): void
    {
        $this->assertSame(2, ShopwiredPaginator::calculatePageCount(100, 50));
        $this->assertSame(4, ShopwiredPaginator::calculatePageCount(100, 25));
        $this->assertSame(1, ShopwiredPaginator::calculatePageCount(50, 50));
    }

    #[Test]
    public function calculate_page_count_rounds_up_with_remainder(): void
    {
        $this->assertSame(3, ShopwiredPaginator::calculatePageCount(101, 50));
        $this->assertSame(2, ShopwiredPaginator::calculatePageCount(51, 50));
        $this->assertSame(4, ShopwiredPaginator::calculatePageCount(99, 25));
    }

    #[Test]
    public function calculate_page_count_with_single_item(): void
    {
        $this->assertSame(1, ShopwiredPaginator::calculatePageCount(1, 50));
        $this->assertSame(1, ShopwiredPaginator::calculatePageCount(1, 100));
        $this->assertSame(1, ShopwiredPaginator::calculatePageCount(1, 1));
    }

    #[Test]
    #[DataProvider('zeroAndNegativeValues')]
    public function calculate_page_count_returns_zero_for_invalid_inputs(
        int $totalItems,
        int $pageSize,
    ): void {
        $this->assertSame(0, ShopwiredPaginator::calculatePageCount($totalItems, $pageSize));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function zeroAndNegativeValues(): array
    {
        return [
            'zero items' => [0, 50],
            'zero page size' => [100, 0],
            'negative items' => [-5, 50],
            'negative page size' => [100, -10],
            'both zero' => [0, 0],
            'both negative' => [-5, -10],
            'negative items zero size' => [-5, 0],
            'zero items negative size' => [0, -10],
        ];
    }
}
