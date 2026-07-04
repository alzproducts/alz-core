<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ListProductsRequestDTO::class)]
final class ListProductsRequestDTOTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | validatedIncludes()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validated_includes_returns_empty_array_when_include_is_null(): void
    {
        $dto = new ListProductsRequestDTO(include: null);

        $this->assertSame([], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_returns_empty_array_when_include_is_empty_string(): void
    {
        $dto = new ListProductsRequestDTO(include: '');

        $this->assertSame([], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_returns_variations_for_variations_string(): void
    {
        $dto = new ListProductsRequestDTO(include: 'variations');

        $this->assertSame(['variations'], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_trims_whitespace_from_include_values(): void
    {
        $dto = new ListProductsRequestDTO(include: ' variations ');

        $this->assertSame(['variations'], $dto->validatedIncludes());
    }

    /*
    |--------------------------------------------------------------------------
    | allowedIncludes()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function allowed_includes_returns_variations_inventory_and_stock(): void
    {
        $this->assertSame(['variations', 'inventory', 'stock'], ListProductsRequestDTO::allowedIncludes());
    }

    /*
    |--------------------------------------------------------------------------
    | Default values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function defaults_are_per_page_500_page_1_include_null(): void
    {
        $dto = new ListProductsRequestDTO();

        $this->assertSame(500, $dto->per_page);
        $this->assertSame(1, $dto->page);
        $this->assertNull($dto->include);
    }

    /*
    |--------------------------------------------------------------------------
    | search filter + sort resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function build_filters_includes_trimmed_search_when_non_empty(): void
    {
        $dto = new ListProductsRequestDTO(search: '  lamp  ');
        $query = $dto->toQuery();

        $this->assertSame('lamp', $query->filters[ProductFilterField::Search->value]);
    }

    #[Test]
    public function build_filters_excludes_search_when_whitespace_only(): void
    {
        $dto = new ListProductsRequestDTO(search: '   ');
        $query = $dto->toQuery();

        $this->assertArrayNotHasKey(ProductFilterField::Search->value, $query->filters);
    }

    #[Test]
    public function build_filters_excludes_search_when_null(): void
    {
        $dto = new ListProductsRequestDTO(search: null);
        $query = $dto->toQuery();

        $this->assertArrayNotHasKey(ProductFilterField::Search->value, $query->filters);
    }

    #[Test]
    public function to_query_uses_explicit_sort_by_when_provided_with_search(): void
    {
        $dto = new ListProductsRequestDTO(sort_by: 'price', search: 'lamp');
        $query = $dto->toQuery();

        $this->assertSame(ProductSortField::Price, $query->sortField);
    }

    #[Test]
    public function to_query_uses_null_sort_field_when_search_present_without_sort_by(): void
    {
        $dto = new ListProductsRequestDTO(search: 'lamp');
        $query = $dto->toQuery();

        $this->assertNull($query->sortField);
    }

    #[Test]
    public function to_query_defaults_sort_field_to_title_when_no_search_and_no_sort_by(): void
    {
        $dto = new ListProductsRequestDTO();
        $query = $dto->toQuery();

        $this->assertSame(ProductSortField::Title, $query->sortField);
    }
}
