<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Repositories;

use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Application\Contracts\Catalog\VariationQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\PopularityBucket;
use App\Domain\Catalog\Product\Enums\VariationFilterField;
use App\Domain\Catalog\Product\Enums\VariationSortField;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Catalog\Product\Mappers\VariationListAssembler;
use App\Infrastructure\Catalog\Product\Mappers\VariationSortFieldMapper;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only Eloquent repository for standalone variation listing.
 *
 * Queries catalog.product_variations_view with denormalized parent context.
 * Uses EloquentGateway directly (no AbstractEloquentRepository — pure query path).
 */
final readonly class EloquentVariationQueryRepository implements VariationQueryRepositoryInterface
{
    /** @var class-string<ProductVariationViewModel> */
    private const string VIEW_MODEL_CLASS = ProductVariationViewModel::class;

    public function __construct(
        private EloquentGateway $eloquentGateway,
        private VariationListAssembler $assembler,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return PaginatedList<VariationListItem>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(VariationListQueryParams $query): PaginatedList
    {
        return $this->eloquentGateway->paginate(
            modelClass: self::VIEW_MODEL_CLASS,
            scope: self::buildScope($query),
            relations: ['product', 'stockItem.suppliers'],
            mapper: fn(ProductVariationViewModel $model): VariationListItem => $this->assembler->toListItem($model, $query->includes),
            perPage: $query->pagination->perPage,
            page: $query->pagination->page,
        );
    }

    private static function buildScope(VariationListQueryParams $query): Closure
    {
        /** @var list<array{VariationFilterField, mixed}> $resolvedFilters */
        $resolvedFilters = [];
        /** @var array{int, int}|null $popularityRange */
        $popularityRange = null;

        foreach ($query->filters as $field => $value) {
            $enum = VariationFilterField::from($field);

            if ($enum === VariationFilterField::PopularityBucket && \is_string($value)) {
                $popularityRange = PopularityBucket::from($value)->rankRange();

                continue;
            }

            $resolvedFilters[] = [$enum, $value];
        }

        return static function (Builder $q) use ($resolvedFilters, $popularityRange, $query): void {
            self::applyFilters($q, $resolvedFilters, $popularityRange);
            self::applySorting($q, $query);
        };
    }

    /**
     * @param Builder<ProductVariationViewModel> $q
     * @param list<array{VariationFilterField, mixed}> $resolvedFilters
     * @param array{int, int}|null $popularityRange
     */
    private static function applyFilters(Builder $q, array $resolvedFilters, ?array $popularityRange): void
    {
        foreach ($resolvedFilters as [$filter, $value]) {
            $_ = match ($filter) {
                VariationFilterField::IsActive => $q->where('parent_is_active', $value),
                VariationFilterField::CategoryId => $q->whereJsonContains('parent_main_category_ids', $value),
                VariationFilterField::IsOnSale => $q->where('is_on_sale', $value),
                VariationFilterField::HasFreeDelivery => $q->where('parent_has_free_delivery', $value),
                VariationFilterField::InStock => $q->where('available_stock', $value === true ? '>' : '<=', 0),
                VariationFilterField::DefaultSupplier => $q->where('default_supplier_name', $value),
                VariationFilterField::PopularityBucket => null,
            };
        }

        if ($popularityRange !== null) {
            $q->whereBetween('popularity_rank', $popularityRange);
        }
    }

    /**
     * @param Builder<ProductVariationViewModel> $q
     */
    private static function applySorting(Builder $q, VariationListQueryParams $query): void
    {
        if ($query->sortField === null) {
            return;
        }

        $column = VariationSortFieldMapper::toColumn($query->sortField);
        $direction = $query->sortDirection->value;

        if ($query->sortField === VariationSortField::Popularity) {
            $q->orderByRaw("{$column} {$direction} NULLS LAST");
        } else {
            $q->orderBy($column, $direction);
        }
    }
}
