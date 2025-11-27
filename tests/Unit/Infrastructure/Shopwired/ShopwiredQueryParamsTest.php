<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShopwiredQueryParams Unit Tests.
 *
 * Tests the immutable value object for ShopWired API query parameters.
 * Covers validation boundaries, fluent builders, pagination, and toArray output.
 */
#[CoversClass(ShopwiredQueryParams::class)]
final class ShopwiredQueryParamsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_params_with_defaults(): void
    {
        $params = new ShopwiredQueryParams();

        $this->assertSame(50, $params->count);
        $this->assertSame(0, $params->offset);
        $this->assertSame([], $params->embeds);
    }

    #[Test]
    public function it_creates_params_with_all_custom_values(): void
    {
        $params = new ShopwiredQueryParams(
            count: 25,
            offset: 100,
            embeds: ['parents', 'children'],
        );

        $this->assertSame(25, $params->count);
        $this->assertSame(100, $params->offset);
        $this->assertSame(['parents', 'children'], $params->embeds);
    }

    /*
    |--------------------------------------------------------------------------
    | Count Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_count_at_minimum_boundary_of_one(): void
    {
        $params = new ShopwiredQueryParams(count: 1);

        $this->assertSame(1, $params->count);
    }

    #[Test]
    public function it_accepts_count_at_maximum_boundary_of_100(): void
    {
        $params = new ShopwiredQueryParams(count: 100);

        $this->assertSame(100, $params->count);
    }

    #[Test]
    #[DataProvider('invalidCountValues')]
    public function it_throws_exception_for_invalid_count(int $invalidCount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Count must be between 1 and 100, got {$invalidCount}");

        new ShopwiredQueryParams(count: $invalidCount);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidCountValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above max' => [101],
            'far above max' => [1000],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Offset Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_offset_at_minimum_boundary_of_zero(): void
    {
        $params = new ShopwiredQueryParams(offset: 0);

        $this->assertSame(0, $params->offset);
    }

    #[Test]
    public function it_accepts_large_offset(): void
    {
        $params = new ShopwiredQueryParams(offset: 10000);

        $this->assertSame(10000, $params->offset);
    }

    #[Test]
    public function it_throws_exception_for_negative_offset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative, got -1');

        new ShopwiredQueryParams(offset: -1);
    }

    #[Test]
    public function it_throws_exception_for_large_negative_offset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be non-negative, got -100');

        new ShopwiredQueryParams(offset: -100);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function for_bulk_fetch_returns_max_count(): void
    {
        $params = ShopwiredQueryParams::forBulkFetch();

        $this->assertSame(100, $params->count);
        $this->assertSame(0, $params->offset);
        $this->assertSame([], $params->embeds);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent Builder Immutability Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_count_returns_new_instance(): void
    {
        $original = new ShopwiredQueryParams(count: 50);
        $modified = $original->withCount(25);

        $this->assertNotSame($original, $modified);
        $this->assertSame(50, $original->count);
        $this->assertSame(25, $modified->count);
    }

    #[Test]
    public function with_count_preserves_other_values(): void
    {
        $original = new ShopwiredQueryParams(count: 50, offset: 100, embeds: ['parents']);
        $modified = $original->withCount(25);

        $this->assertSame(100, $modified->offset);
        $this->assertSame(['parents'], $modified->embeds);
    }

    #[Test]
    public function with_offset_returns_new_instance(): void
    {
        $original = new ShopwiredQueryParams(offset: 0);
        $modified = $original->withOffset(100);

        $this->assertNotSame($original, $modified);
        $this->assertSame(0, $original->offset);
        $this->assertSame(100, $modified->offset);
    }

    #[Test]
    public function with_offset_preserves_other_values(): void
    {
        $original = new ShopwiredQueryParams(count: 25, offset: 0, embeds: ['children']);
        $modified = $original->withOffset(100);

        $this->assertSame(25, $modified->count);
        $this->assertSame(['children'], $modified->embeds);
    }

    #[Test]
    public function with_embeds_returns_new_instance(): void
    {
        $original = new ShopwiredQueryParams(embeds: []);
        $modified = $original->withEmbeds(['parents']);

        $this->assertNotSame($original, $modified);
        $this->assertSame([], $original->embeds);
        $this->assertSame(['parents'], $modified->embeds);
    }

    #[Test]
    public function with_embeds_preserves_other_values(): void
    {
        $original = new ShopwiredQueryParams(count: 25, offset: 100, embeds: []);
        $modified = $original->withEmbeds(['parents', 'children']);

        $this->assertSame(25, $modified->count);
        $this->assertSame(100, $modified->offset);
    }

    #[Test]
    public function with_embeds_replaces_previous_embeds(): void
    {
        $original = new ShopwiredQueryParams(embeds: ['parents']);
        $modified = $original->withEmbeds(['children']);

        $this->assertSame(['children'], $modified->embeds);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function next_page_advances_offset_by_count(): void
    {
        $params = new ShopwiredQueryParams(count: 50, offset: 0);
        $nextPage = $params->nextPage();

        $this->assertSame(50, $nextPage->offset);
    }

    #[Test]
    public function next_page_returns_new_instance(): void
    {
        $params = new ShopwiredQueryParams(count: 50, offset: 0);
        $nextPage = $params->nextPage();

        $this->assertNotSame($params, $nextPage);
        $this->assertSame(0, $params->offset);
    }

    #[Test]
    public function next_page_preserves_count_and_embeds(): void
    {
        $params = new ShopwiredQueryParams(count: 25, offset: 0, embeds: ['parents']);
        $nextPage = $params->nextPage();

        $this->assertSame(25, $nextPage->count);
        $this->assertSame(['parents'], $nextPage->embeds);
    }

    #[Test]
    public function next_page_accumulates_offset(): void
    {
        $params = new ShopwiredQueryParams(count: 100, offset: 0);

        $page2 = $params->nextPage();
        $page3 = $page2->nextPage();
        $page4 = $page3->nextPage();

        $this->assertSame(100, $page2->offset);
        $this->assertSame(200, $page3->offset);
        $this->assertSame(300, $page4->offset);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray Output Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_includes_count_and_offset(): void
    {
        $params = new ShopwiredQueryParams(count: 25, offset: 100);

        $array = $params->toArray();

        $this->assertSame(25, $array['count']);
        $this->assertSame(100, $array['offset']);
    }

    #[Test]
    public function to_array_excludes_embed_when_empty(): void
    {
        $params = new ShopwiredQueryParams(embeds: []);

        $array = $params->toArray();

        $this->assertArrayNotHasKey('embed', $array);
    }

    #[Test]
    public function to_array_includes_single_embed(): void
    {
        $params = new ShopwiredQueryParams(embeds: ['parents']);

        $array = $params->toArray();

        $this->assertSame('parents', $array['embed']);
    }

    #[Test]
    public function to_array_joins_multiple_embeds_with_comma(): void
    {
        $params = new ShopwiredQueryParams(embeds: ['parents', 'children', 'images']);

        $array = $params->toArray();

        $this->assertSame('parents,children,images', $array['embed']);
    }

    /*
    |--------------------------------------------------------------------------
    | isFirstPage Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_first_page_returns_true_when_offset_is_zero(): void
    {
        $params = new ShopwiredQueryParams(offset: 0);

        $this->assertTrue($params->isFirstPage());
    }

    #[Test]
    public function is_first_page_returns_false_when_offset_is_non_zero(): void
    {
        $params = new ShopwiredQueryParams(offset: 1);

        $this->assertFalse($params->isFirstPage());
    }

    #[Test]
    public function is_first_page_returns_false_after_next_page(): void
    {
        $params = new ShopwiredQueryParams(count: 50, offset: 0);
        $nextPage = $params->nextPage();

        $this->assertTrue($params->isFirstPage());
        $this->assertFalse($nextPage->isFirstPage());
    }

    /*
    |--------------------------------------------------------------------------
    | Constants Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_max_count_constant(): void
    {
        $this->assertSame(100, ShopwiredQueryParams::MAX_COUNT);
    }

    #[Test]
    public function it_exposes_default_count_constant(): void
    {
        $this->assertSame(50, ShopwiredQueryParams::DEFAULT_COUNT);
    }
}
