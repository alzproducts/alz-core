<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\OrderQueryParams;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OrderQueryParams Unit Tests.
 *
 * Tests the immutable value object for ShopWired Order API query parameters.
 * Covers order-specific filters (from, to, status, archived), pagination delegation,
 * and toArray output generation.
 */
#[CoversClass(OrderQueryParams::class)]
final class OrderQueryParamsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_params_with_defaults(): void
    {
        $params = new OrderQueryParams();

        self::assertNull($params->from);
        self::assertNull($params->to);
        self::assertNull($params->status);
        self::assertNull($params->archived);
    }

    #[Test]
    public function it_creates_params_with_all_custom_values(): void
    {
        $from = 1700000000;
        $to = 1700100000;
        $status = 73879;

        $params = new OrderQueryParams(
            from: $from,
            to: $to,
            status: $status,
            archived: true,
        );

        self::assertSame($from, $params->from);
        self::assertSame($to, $params->to);
        self::assertSame($status, $params->status);
        self::assertTrue($params->archived);
    }

    #[Test]
    public function for_bulk_fetch_uses_max_page_size(): void
    {
        $params = OrderQueryParams::forBulkFetch();

        // ShopwiredQueryParams::forBulkFetch() sets count to MAX_COUNT (100)
        self::assertSame(ShopwiredQueryParams::MAX_COUNT, $params->getCount());
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent Builder Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_from_returns_new_instance_with_updated_from(): void
    {
        $original = new OrderQueryParams();
        $modified = $original->withFrom(1700000000);

        self::assertNull($original->from);
        self::assertSame(1700000000, $modified->from);
    }

    #[Test]
    public function with_to_returns_new_instance_with_updated_to(): void
    {
        $original = new OrderQueryParams();
        $modified = $original->withTo(1700100000);

        self::assertNull($original->to);
        self::assertSame(1700100000, $modified->to);
    }

    #[Test]
    public function with_status_returns_new_instance_with_updated_status(): void
    {
        $original = new OrderQueryParams();
        $modified = $original->withStatus(73879);

        self::assertNull($original->status);
        self::assertSame(73879, $modified->status);
    }

    #[Test]
    public function with_archived_returns_new_instance_with_updated_archived(): void
    {
        $original = new OrderQueryParams();
        $modifiedTrue = $original->withArchived(true);
        $modifiedFalse = $original->withArchived(false);

        self::assertNull($original->archived);
        self::assertTrue($modifiedTrue->archived);
        self::assertFalse($modifiedFalse->archived);
    }

    #[Test]
    public function with_base_params_returns_new_instance_with_custom_base(): void
    {
        $original = OrderQueryParams::forBulkFetch();
        $customBase = new ShopwiredQueryParams(count: 10, offset: 50);
        $modified = $original->withBaseParams($customBase);

        self::assertSame(ShopwiredQueryParams::MAX_COUNT, $original->getCount());
        self::assertSame(10, $modified->getCount());
    }

    #[Test]
    public function with_count_returns_new_instance_with_updated_count(): void
    {
        $original = new OrderQueryParams();
        $modified = $original->withCount(100);

        self::assertSame(50, $original->getCount()); // Default is 50
        self::assertSame(100, $modified->getCount());
    }

    #[Test]
    public function with_offset_returns_new_instance_with_updated_offset(): void
    {
        $original = new OrderQueryParams();
        $modified = $original->withOffset(100);

        self::assertTrue($original->isFirstPage());
        self::assertFalse($modified->isFirstPage());
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function next_page_advances_offset_while_preserving_filters(): void
    {
        $original = OrderQueryParams::forBulkFetch()
            ->withFrom(1700000000)
            ->withTo(1700100000)
            ->withStatus(73879)
            ->withArchived(true);

        $nextPage = $original->nextPage();

        // Filters preserved
        self::assertSame($original->from, $nextPage->from);
        self::assertSame($original->to, $nextPage->to);
        self::assertSame($original->status, $nextPage->status);
        self::assertSame($original->archived, $nextPage->archived);

        // Offset advanced
        self::assertTrue($original->isFirstPage());
        self::assertFalse($nextPage->isFirstPage());
    }

    /*
    |--------------------------------------------------------------------------
    | toArray Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_excludes_null_order_specific_params(): void
    {
        $params = new OrderQueryParams();
        $array = $params->toArray();

        self::assertArrayNotHasKey('from', $array);
        self::assertArrayNotHasKey('to', $array);
        self::assertArrayNotHasKey('status', $array);
        self::assertArrayNotHasKey('archived', $array);
    }

    #[Test]
    public function to_array_includes_from_when_set(): void
    {
        $params = (new OrderQueryParams())->withFrom(1700000000);
        $array = $params->toArray();

        self::assertArrayHasKey('from', $array);
        self::assertSame(1700000000, $array['from']);
    }

    #[Test]
    public function to_array_includes_to_when_set(): void
    {
        $params = (new OrderQueryParams())->withTo(1700100000);
        $array = $params->toArray();

        self::assertArrayHasKey('to', $array);
        self::assertSame(1700100000, $array['to']);
    }

    #[Test]
    public function to_array_includes_status_when_set(): void
    {
        $params = (new OrderQueryParams())->withStatus(73879);
        $array = $params->toArray();

        self::assertArrayHasKey('status', $array);
        self::assertSame(73879, $array['status']);
    }

    #[Test]
    public function to_array_converts_archived_true_to_string_1(): void
    {
        $params = (new OrderQueryParams())->withArchived(true);
        $array = $params->toArray();

        self::assertArrayHasKey('archived', $array);
        self::assertSame('1', $array['archived']);
    }

    #[Test]
    public function to_array_converts_archived_false_to_string_0(): void
    {
        $params = (new OrderQueryParams())->withArchived(false);
        $array = $params->toArray();

        self::assertArrayHasKey('archived', $array);
        self::assertSame('0', $array['archived']);
    }

    #[Test]
    public function to_array_includes_all_params_when_fully_configured(): void
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom(1700000000)
            ->withTo(1700100000)
            ->withStatus(73879)
            ->withArchived(true);

        $array = $params->toArray();

        // Order-specific params
        self::assertSame(1700000000, $array['from']);
        self::assertSame(1700100000, $array['to']);
        self::assertSame(73879, $array['status']);
        self::assertSame('1', $array['archived']);

        // Base params (from ShopwiredQueryParams)
        self::assertArrayHasKey('count', $array);
        self::assertArrayHasKey('offset', $array);
    }
}
