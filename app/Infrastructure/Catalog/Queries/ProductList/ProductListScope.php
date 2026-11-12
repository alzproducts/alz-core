<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Infrastructure\Catalog\Product\Mappers\ProductSortFieldMapper;
use Illuminate\Database\Query\Builder;

/**
 * Filters, search and ordering of GET /api/products over catalog.products_view.
 *
 * The id tiebreak keeps paging stable when rows tie on the sort value (COR-244).
 */
final class ProductListScope
{
    public static function apply(Builder $q, ProductListQueryParams $query): void
    {
        foreach ($query->filters as $field => $value) {
            self::applyFilter($q, ProductFilterField::tryFrom($field), $value);
        }

        $searchFilter = $query->filters[ProductFilterField::Search->value] ?? null;

        self::applyOrdering($q, $query, \is_string($searchFilter) ? $searchFilter : null);
    }

    private static function applyFilter(Builder $q, ProductFilterField $filter, mixed $value): void
    {
        $_ = match ($filter) {
            ProductFilterField::IsActive => $q->where('is_active', $value),
            ProductFilterField::CategoryId => $q->whereJsonContains('category_ids', $value),
            ProductFilterField::IsOnSale => $q->where('is_on_sale', $value),
            ProductFilterField::HasAnySale => $q->where('has_any_sale', $value),
            ProductFilterField::Sku => $q->where('sku', $value),
            ProductFilterField::HasFreeDelivery => $q->where('has_free_delivery', $value),
            ProductFilterField::Search => $q->whereRaw(
                "to_tsvector('english', title) @@ websearch_to_tsquery('english', ?)",
                [$value],
            ),
        };
    }

    private static function applyOrdering(Builder $q, ProductListQueryParams $query, ?string $searchTerm): void
    {
        if ($query->sortField !== null) {
            $q->orderBy(ProductSortFieldMapper::toColumn($query->sortField), $query->sortDirection->value)->orderBy('id');
        } elseif ($searchTerm !== null) {
            $q->orderByRaw(
                "ts_rank(to_tsvector('english', title), websearch_to_tsquery('english', ?)) DESC",
                [$searchTerm],
            )->orderBy('title')->orderBy('id');
        } else {
            $q->orderBy('id');
        }
    }
}
