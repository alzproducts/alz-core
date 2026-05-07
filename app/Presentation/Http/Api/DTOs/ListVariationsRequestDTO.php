<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Catalog\Queries\VariationListQueryParams;
use App\Domain\Catalog\Product\Enums\PopularityBucket;
use App\Domain\Catalog\Product\Enums\VariationFilterField;
use App\Domain\Catalog\Product\Enums\VariationInclude;
use App\Domain\Catalog\Product\Enums\VariationSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Presentation\Http\Api\Traits\ValidatesIncludesTrait;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/variations.
 *
 * Validates pagination bounds, include parameter, optional sort/filter fields.
 */
final class ListVariationsRequestDTO extends Data
{
    use ValidatesIncludesTrait;

    public function __construct(
        #[IntegerType, Min(1), Max(1000)]
        public readonly int $per_page = 500,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
        #[Nullable, StringType]
        public readonly ?string $include = null,
        #[Nullable, StringType]
        public readonly ?string $sort_by = null,
        #[Nullable, StringType]
        public readonly ?string $sort_direction = null,
        #[Nullable, IntegerType]
        public readonly ?int $category_id = null,
        #[Nullable, BooleanType]
        public readonly ?bool $is_on_sale = null,
        #[Nullable, BooleanType]
        public readonly ?bool $has_free_delivery = null,
        #[Nullable, BooleanType]
        public readonly ?bool $is_active = null,
        #[Nullable, BooleanType]
        public readonly ?bool $in_stock = null,
        #[Nullable, StringType]
        public readonly ?string $default_supplier = null,
        #[Nullable, StringType]
        public readonly ?string $popularity_bucket = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'include' => self::includeRules(),
            'sort_by' => ['nullable', 'string', 'in:' . \implode(',', \array_column(VariationSortField::cases(), 'value'))],
            'sort_direction' => ['nullable', 'string', 'in:' . \implode(',', \array_column(SortDirection::cases(), 'value'))],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'popularity_bucket' => ['nullable', 'string', 'in:' . \implode(',', \array_column(PopularityBucket::cases(), 'value'))],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedIncludes(): array
    {
        return VariationInclude::values();
    }

    public function toQuery(): VariationListQueryParams
    {
        return new VariationListQueryParams(
            pagination: PageRequest::from(page: $this->page, perPage: $this->per_page),
            includes: \array_map(VariationInclude::fromValue(...), $this->validatedIncludes()),
            sortField: $this->sort_by !== null ? VariationSortField::from($this->sort_by) : null,
            sortDirection: $this->sort_direction !== null ? SortDirection::from($this->sort_direction) : SortDirection::Asc,
            filters: $this->buildFilters(),
        );
    }

    /**
     * @return array<value-of<VariationFilterField>, mixed>
     */
    private function buildFilters(): array
    {
        return \array_filter([
            VariationFilterField::IsActive->value => $this->is_active,
            VariationFilterField::CategoryId->value => $this->category_id,
            VariationFilterField::IsOnSale->value => $this->is_on_sale,
            VariationFilterField::HasFreeDelivery->value => $this->has_free_delivery,
            VariationFilterField::InStock->value => $this->in_stock,
            VariationFilterField::DefaultSupplier->value => $this->default_supplier,
            VariationFilterField::PopularityBucket->value => $this->popularity_bucket,
        ], static fn(mixed $v): bool => $v !== null);
    }
}
