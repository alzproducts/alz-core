<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Queries;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductListQueryParams::class)]
final class ProductListQueryParamsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Constructor defaults
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function constructor_defaults_sort_field_to_null(): void
    {
        $params = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        self::assertNull($params->sortField);
    }

    #[Test]
    public function constructor_defaults_sort_direction_to_asc(): void
    {
        $params = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        self::assertSame(SortDirection::Asc, $params->sortDirection);
    }

    #[Test]
    public function constructor_defaults_includes_to_empty_array(): void
    {
        $params = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        self::assertSame([], $params->includes);
    }

    #[Test]
    public function constructor_defaults_filters_to_empty_array(): void
    {
        $params = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        self::assertSame([], $params->filters);
    }

    /*
    |--------------------------------------------------------------------------
    | active() static factory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function active_factory_sets_sort_field_to_title(): void
    {
        $params = ProductListQueryParams::active(PageRequest::from(1, 10));

        self::assertSame(ProductSortField::Title, $params->sortField);
    }

    #[Test]
    public function active_factory_sets_sort_direction_to_asc(): void
    {
        $params = ProductListQueryParams::active(PageRequest::from(1, 10));

        self::assertSame(SortDirection::Asc, $params->sortDirection);
    }

    #[Test]
    public function active_factory_sets_is_active_filter_to_true(): void
    {
        $params = ProductListQueryParams::active(PageRequest::from(1, 10));

        self::assertSame([ProductFilterField::IsActive->value => true], $params->filters);
    }

    #[Test]
    public function active_factory_passes_includes_through(): void
    {
        $includes = [ProductInclude::Variations, ProductInclude::Description];

        $params = ProductListQueryParams::active(PageRequest::from(1, 10), $includes);

        self::assertSame($includes, $params->includes);
    }

    #[Test]
    public function active_factory_defaults_includes_to_empty_array(): void
    {
        $params = ProductListQueryParams::active(PageRequest::from(1, 10));

        self::assertSame([], $params->includes);
    }

    /*
    |--------------------------------------------------------------------------
    | hasInclude()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_include_returns_true_when_include_is_in_list(): void
    {
        $params = new ProductListQueryParams(
            pagination: PageRequest::from(1, 10),
            includes: [ProductInclude::Variations],
        );

        self::assertTrue($params->hasInclude(ProductInclude::Variations));
    }

    #[Test]
    public function has_include_returns_false_when_include_is_not_in_list(): void
    {
        $params = new ProductListQueryParams(
            pagination: PageRequest::from(1, 10),
            includes: [ProductInclude::Variations],
        );

        self::assertFalse($params->hasInclude(ProductInclude::Description));
    }

    #[Test]
    public function has_include_returns_false_when_includes_list_is_empty(): void
    {
        $params = new ProductListQueryParams(pagination: PageRequest::from(1, 10));

        self::assertFalse($params->hasInclude(ProductInclude::Variations));
    }
}
