<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Presentation\Http\Api\Traits\ValidatesIncludesTrait;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/products.
 *
 * Validates pagination bounds, include parameter, and optional sort/filter fields.
 */
final class ListProductsRequestDTO extends Data
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
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'include' => self::includeRules(),
            'sort_by' => ['nullable', 'string', 'in:' . \implode(',', \array_column(ProductSortField::cases(), 'value'))],
            'sort_direction' => ['nullable', 'string', 'in:' . \implode(',', \array_column(SortDirection::cases(), 'value'))],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedIncludes(): array
    {
        return [ProductInclude::Variations->value];
    }

    /**
     * Build a ProductListQueryParams from this validated request.
     */
    public function toQuery(): ProductListQueryParams
    {
        return new ProductListQueryParams(
            pagination: PageRequest::from(page: $this->page, perPage: $this->per_page),
            includes: \array_map(ProductInclude::fromValue(...), $this->validatedIncludes()),
            sortField: $this->sort_by !== null ? ProductSortField::from($this->sort_by) : ProductSortField::Title,
            sortDirection: $this->sort_direction !== null ? SortDirection::from($this->sort_direction) : SortDirection::Asc,
            filters: [ProductFilterField::IsActive->value => true],
        );
    }
}
