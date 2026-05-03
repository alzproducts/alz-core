<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Repositories;

use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Application\Contracts\Catalog\VariationQueryRepositoryInterface;
use App\Domain\Catalog\Product\Enums\VariationFilterField;
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
        foreach ($query->filters as $field => $value) {
            $resolvedFilters[] = [VariationFilterField::from($field), $value];
        }

        return static function (Builder $q) use ($resolvedFilters, $query): void {
            foreach ($resolvedFilters as [$filter, $value]) {
                $_ = match ($filter) {
                    VariationFilterField::IsActive => $q->where('parent_is_active', $value),
                    VariationFilterField::CategoryId => $q->whereJsonContains('parent_main_category_ids', $value),
                    VariationFilterField::IsOnSale => $q->where('is_on_sale', $value),
                    VariationFilterField::HasFreeDelivery => $q->where('parent_has_free_delivery', $value),
                };
            }

            if ($query->sortField !== null) {
                $q->orderBy(VariationSortFieldMapper::toColumn($query->sortField), $query->sortDirection->value);
            }
        };
    }
}
