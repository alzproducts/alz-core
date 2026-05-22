<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Catalog\Product\ValueObjects\Popularity;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\ValueObjects\PaginatedList;
use App\Presentation\Http\Api\Controllers\ProductController;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(ProductController::class)]
final class ProductControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private ProductRepositoryInterface&MockInterface $productRepository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->app->instance(ProductRepositoryInterface::class, $this->productRepository);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function unauthenticated_request_returns_401_json(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Missing authorization token.']]);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function authenticated_request_returns_paginated_products_with_data_meta_links(): void
    {
        $product = $this->createProduct(id: 1, title: 'Test Product');
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 50, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('links', $body);
        $this->assertCount(1, $body['data']);
        $this->assertSame(1, $body['data'][0]['id']);
        $this->assertSame('Test Product', $body['data'][0]['title']);
    }

    #[Test]
    public function include_variations_parameter_passes_through_to_use_case(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 500 && $q->pagination->page === 1 && $q->includes === [ProductInclude::Variations]))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=variations');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter pass-through
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function category_id_filter_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::CategoryId->value] ?? null) === 42))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?category_id=42');

        $response->assertStatus(200);
    }

    #[Test]
    public function is_on_sale_filter_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::IsOnSale->value] ?? null) === true))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?is_on_sale=1');

        $response->assertStatus(200);
    }

    #[Test]
    public function sku_filter_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::Sku->value] ?? null) === 'ABC-123'))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sku=ABC-123');

        $response->assertStatus(200);
    }

    #[Test]
    public function has_free_delivery_filter_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::HasFreeDelivery->value] ?? null) === true))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?has_free_delivery=1');

        $response->assertStatus(200);
    }

    #[Test]
    public function omitted_filters_are_not_included_in_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => \count($q->filters) === 0))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalid_include_parameter_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?include=foo');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function per_page_below_minimum_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?per_page=0');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function per_page_above_maximum_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?per_page=1001');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function category_id_below_minimum_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?category_id=0');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function sku_exceeding_max_length_returns_422(): void
    {
        $longSku = \str_repeat('A', 101);

        $response = $this->asApprovedUser()->getJson('/api/products?sku=' . $longSku);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination defaults and meta
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function default_per_page_is_500_and_page_is_1(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 500 && $q->pagination->page === 1 && $q->includes === []))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(500, $body['meta']['per_page']);
        $this->assertSame(1, $body['meta']['current_page']);
    }

    #[Test]
    public function pagination_links_preserve_query_string_parameters(): void
    {
        $items = \array_fill(0, 50, $this->createProduct(id: 1, title: 'Product'));
        $dto = PaginatedList::fromPage(items: $items, total: 200, perPage: 50, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?per_page=50');

        $response->assertStatus(200);
        $body = $response->json();
        // Next page link should include per_page in query string
        $this->assertStringContainsString('per_page=50', $body['links']['next'] ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | Sort field pass-through
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function sort_by_title_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Title))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=title');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_price_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Price))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=price');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_effective_price_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::EffectivePrice))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=effective_price');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_stock_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Stock))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=stock');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_profit_margin_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::ProfitMargin))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=profit_margin');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_created_at_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::CreatedAt))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=created_at');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_by_updated_at_passes_sort_field_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::UpdatedAt))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=updated_at');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_direction_asc_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortDirection === SortDirection::Asc))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_direction=asc');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_direction_desc_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortDirection === SortDirection::Desc))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_direction=desc');

        $response->assertStatus(200);
    }

    #[Test]
    public function default_sort_is_title_asc_when_sort_params_omitted(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Title
                && $q->sortDirection === SortDirection::Asc))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Sort validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalid_sort_by_value_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=invalid');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function invalid_sort_direction_value_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?sort_direction=invalid');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Combination pass-through
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function multiple_filters_combine_correctly(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => \count($q->filters) === 3
                    && ($q->filters[ProductFilterField::CategoryId->value] ?? null) === 42
                    && ($q->filters[ProductFilterField::IsOnSale->value] ?? null) === true
                    && ($q->filters[ProductFilterField::Sku->value] ?? null) === 'ABC-123'))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?category_id=42&is_on_sale=1&sku=ABC-123');

        $response->assertStatus(200);
    }

    #[Test]
    public function sort_and_filter_combine_correctly(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Price
                    && $q->sortDirection === SortDirection::Desc
                    && ($q->filters[ProductFilterField::CategoryId->value] ?? null) === 7))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=price&sort_direction=desc&category_id=7');

        $response->assertStatus(200);
    }

    #[Test]
    public function full_combination_of_sort_filter_pagination_and_include(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 25, currentPage: 3);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::EffectivePrice
                    && $q->sortDirection === SortDirection::Asc
                    && $q->pagination->perPage === 25
                    && $q->pagination->page === 3
                    && $q->includes === [ProductInclude::Variations]
                    && ($q->filters[ProductFilterField::CategoryId->value] ?? null) === 5
                    && ($q->filters[ProductFilterField::IsOnSale->value] ?? null) === true))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=effective_price&sort_direction=asc&category_id=5&is_on_sale=1&per_page=25&page=3&include=variations');

        $response->assertStatus(200);
    }

    #[Test]
    public function kitchen_sink_all_params_at_once(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 100, currentPage: 2);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->sortField === ProductSortField::Stock
                    && $q->sortDirection === SortDirection::Desc
                    && $q->pagination->perPage === 100
                    && $q->pagination->page === 2
                    && $q->includes === [ProductInclude::Variations]
                    && \count($q->filters) === 4
                    && ($q->filters[ProductFilterField::CategoryId->value] ?? null) === 99
                    && ($q->filters[ProductFilterField::IsOnSale->value] ?? null) === true
                    && ($q->filters[ProductFilterField::Sku->value] ?? null) === 'FULL-TEST'
                    && ($q->filters[ProductFilterField::HasFreeDelivery->value] ?? null) === true))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?sort_by=stock&sort_direction=desc&category_id=99&is_on_sale=1&sku=FULL-TEST&has_free_delivery=1&per_page=100&page=2&include=variations');

        $response->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter edge cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_on_sale_false_passes_false_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::IsOnSale->value] ?? null) === false))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?is_on_sale=0');

        $response->assertStatus(200);
    }

    #[Test]
    public function has_free_delivery_false_passes_false_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::HasFreeDelivery->value] ?? null) === false))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?has_free_delivery=0');

        $response->assertStatus(200);
    }

    #[Test]
    public function is_active_true_passes_true_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::IsActive->value] ?? null) === true))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?is_active=1');

        $response->assertStatus(200);
    }

    #[Test]
    public function is_active_false_passes_false_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => ($q->filters[ProductFilterField::IsActive->value] ?? null) === false))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?is_active=0');

        $response->assertStatus(200);
    }

    #[Test]
    public function omitted_is_active_does_not_add_filter(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => !\array_key_exists(ProductFilterField::IsActive->value, $q->filters)))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
    }

    #[Test]
    public function negative_category_id_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?category_id=-1');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function non_integer_category_id_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?category_id=abc');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    #[Test]
    public function is_on_sale_non_boolean_string_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?is_on_sale=yes');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination edge cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function per_page_minimum_boundary_accepted(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 1, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 1))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?per_page=1');

        $response->assertStatus(200);
    }

    #[Test]
    public function per_page_maximum_boundary_accepted(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 1000, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->perPage === 1000))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?per_page=1000');

        $response->assertStatus(200);
    }

    #[Test]
    public function page_number_passes_through_to_query(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 2);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(Mockery::on(static fn(ProductListQueryParams $q): bool => $q->pagination->page === 2))
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?page=2');

        $response->assertStatus(200);
    }

    #[Test]
    public function page_below_minimum_returns_422(): void
    {
        $response = $this->asApprovedUser()->getJson('/api/products?page=0');

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('validation_error', $body['error']['type']);
    }

    /*
    |--------------------------------------------------------------------------
    | Response structure
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function response_contains_all_product_resource_fields(): void
    {
        $product = $this->createProduct(id: 42, title: 'Full Field Product');
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $body = $response->json();
        $productData = $body['data'][0];

        $expectedKeys = [
            'id', 'sku', 'title', 'slug', 'links',
            'price', 'cost_price', 'sale_price', 'rrp',
            'effective_price', 'profit_margin',
            'is_active', 'is_on_sale', 'has_any_sale', 'has_single_selling_price', 'has_free_delivery',
            'vat_exclusive', 'vat_relief',
            'meta_title', 'meta_description', 'free_delivery',
            'sort_order', 'popularity', 'images', 'created_at', 'updated_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $productData, "Missing key: {$key}");
        }

        // Spot-check values
        $this->assertSame(42, $productData['id']);
        $this->assertSame('Full Field Product', $productData['title']);
        $this->assertSame(9.99, $productData['price']);
        $this->assertTrue($productData['is_active']);
        $this->assertFalse($productData['is_on_sale']);
        $this->assertNull($productData['popularity']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/',
            $productData['created_at'],
        );
    }

    #[Test]
    public function response_exposes_nested_popularity_object_when_present(): void
    {
        $product = $this->createProduct(
            id: 42,
            title: 'Popular Product',
            popularity: new Popularity(rank: 3, max: 12),
        );
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $productData = $response->json()['data'][0];

        $this->assertSame(
            ['rank' => 3, 'max' => 12, 'level' => 5],
            $productData['popularity'],
        );
    }

    #[Test]
    public function response_includes_variation_fields_when_requested(): void
    {
        $product = $this->createProductWithVariations();
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=variations');

        $response->assertStatus(200);
        $body = $response->json();
        $variation = $body['data'][0]['variations'][0];

        $expectedKeys = [
            'id', 'sku', 'gtin', 'price', 'cost_price', 'sale_price', 'rrp',
            'effective_price', 'profit_margin', 'is_on_sale',
            'stock', 'weight', 'image_index', 'options',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $variation, "Missing variation key: {$key}");
        }

        // Spot-check values
        $this->assertSame(101, $variation['id']);
        $this->assertSame('VAR-1', $variation['sku']);
        $this->assertSame(5.99, $variation['price']);
        $this->assertSame(2.5, $variation['cost_price']);
        $this->assertNull($variation['rrp']);
        $this->assertSame(5, $variation['stock']);
        $this->assertFalse($variation['is_on_sale']);
        $this->assertSame(58.26, $variation['profit_margin']);
    }

    #[Test]
    public function negative_available_stock_is_clamped_to_zero_in_response(): void
    {
        $variation = new ProductVariationView(
            externalId: 61599625,
            sku: 'FS-ABR/BUDGET F',
            gtin: null,
            price: 5.99,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 5.99,
            isOnSale: false,
            profitMargin: null,
            availableStock: -4,
            physicalStock: 0,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );

        $product = new ProductView(
            externalId: 42,
            sku: null,
            gtin: null,
            title: 'Oversold Product',
            description: null,
            slug: 'oversold-product',
            links: new ProductLinks(
                publicUrl: 'https://example.com/oversold-product',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/42',
            ),
            price: 9.99,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 9.99,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: [$variation],
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta([$variation], null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale([$variation]),
            parentAvailableStock: -4,
            parentPhysicalStock: 0,
            allVariations: [$variation],
        );

        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=variations');

        $response->assertStatus(200);
        $variationJson = $response->json()['data'][0]['variations'][0];
        $this->assertSame(0, $variationJson['stock']);
    }

    #[Test]
    public function variant_only_product_aggregates_stock_under_include_stock_and_exposes_common_price(): void
    {
        $product = $this->createVariantOnlyProduct(variationPrices: [12.99, 12.99], stockPerVariation: 3);
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=stock');

        $response->assertStatus(200);
        $body = $response->json();
        $productData = $body['data'][0];

        // Regression: master-level stock/price were zero; variation-level must fill the gap.
        $this->assertSame(6, $productData['stock']['aggregate_available']);
        $this->assertSame(6, $productData['stock']['aggregate_physical']);
        $this->assertSame(12.99, $productData['price']);
        $this->assertSame(12.99, $productData['effective_price']);
        $this->assertTrue($productData['has_single_selling_price']);
    }

    #[Test]
    public function variant_only_product_with_differing_prices_uses_minimum_and_reports_non_uniform(): void
    {
        $product = $this->createVariantOnlyProduct(variationPrices: [19.99, 24.99, 14.99], stockPerVariation: 2);
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=stock');

        $response->assertStatus(200);
        $body = $response->json();
        $productData = $body['data'][0];

        // Non-uniform variation prices: aggregate to the minimum, flag as not single-priced.
        $this->assertSame(6, $productData['stock']['aggregate_available']);
        $this->assertSame(14.99, $productData['price']);
        $this->assertSame(14.99, $productData['effective_price']);
        $this->assertFalse($productData['has_single_selling_price']);
    }

    /*
    |--------------------------------------------------------------------------
    | stock_status (promoted ShopWired custom fields)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function stock_status_is_present_on_every_product_with_null_defaults(): void
    {
        $product = $this->createProduct(id: 1, title: 'Plain Product');
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $stockStatus = $response->json()['data'][0]['stock_status'];
        $this->assertSame(
            ['discontinued' => null, 'preorder_date' => null, 'other_stock_status' => null],
            $stockStatus,
        );
    }

    #[Test]
    public function stock_status_populated_fields_appear_in_response_with_atom_date(): void
    {
        $preorderDate = new DateTimeImmutable('2026-06-15T00:00:00+00:00');
        $product = $this->createProduct(
            id: 1,
            title: 'Preorder Product',
            discontinued: 'yes',
            preorderDate: $preorderDate,
            otherStockStatus: 'awaiting_restock',
        );
        $dto = PaginatedList::fromPage(items: [$product], total: 1, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $stockStatus = $response->json()['data'][0]['stock_status'];
        $this->assertSame('yes', $stockStatus['discontinued']);
        $this->assertSame($preorderDate->format(DateTimeInterface::ATOM), $stockStatus['preorder_date']);
        $this->assertSame('awaiting_restock', $stockStatus['other_stock_status']);
    }

    #[Test]
    public function empty_result_set_returns_correct_structure(): void
    {
        $dto = PaginatedList::fromPage(items: [], total: 0, perPage: 500, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['meta']['total']);
        $this->assertSame(500, $body['meta']['per_page']);
        $this->assertSame(1, $body['meta']['current_page']);
    }

    /*
    |--------------------------------------------------------------------------
    | Show endpoint (GET /api/products/{id})
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function show_returns_variations_without_explicit_include(): void
    {
        $product = $this->createProductWithVariations();

        $this->productRepository
            ->shouldReceive('findProductView')
            ->once()
            ->with(Mockery::on(static fn(ProductDetailQueryParams $q): bool => $q->productId->value === 42 && $q->includes === []))
            ->andReturn($product);

        $response = $this->asApprovedUser()->getJson('/api/products/42');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertArrayHasKey('variations', $body['data']);
        $this->assertNotEmpty($body['data']['variations']);
        $this->assertSame(101, $body['data']['variations'][0]['id']);
    }

    #[Test]
    public function show_returns_variations_alongside_other_includes(): void
    {
        $product = $this->createProductWithVariations();

        $this->productRepository
            ->shouldReceive('findProductView')
            ->once()
            ->with(Mockery::on(static fn(ProductDetailQueryParams $q): bool => $q->productId->value === 42
                && \in_array(ProductInclude::Description, $q->includes, true)
                && ! \in_array(ProductInclude::Variations, $q->includes, true)))
            ->andReturn($product);

        $response = $this->asApprovedUser()->getJson('/api/products/42?include=description');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertArrayHasKey('variations', $body['data']);
        $this->assertNotEmpty($body['data']['variations']);
    }

    /*
    |--------------------------------------------------------------------------
    | Regression: COR-87 — missing Accept header must still return JSON
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function show_non_existent_product_without_accept_header_returns_json_404(): void
    {
        $this->productRepository
            ->shouldReceive('findProductView')
            ->once()
            ->andThrow(new RecordNotFoundException('products', 99999));

        $response = $this->asApprovedUser()->get('/api/products/99999');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('error.type', 'not_found');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */


    private function createProductWithVariations(): ProductView
    {
        $variation = new ProductVariationView(
            externalId: 101,
            sku: 'VAR-1',
            gtin: null,
            price: 5.99,
            costPrice: 2.50,
            salePrice: null,
            rrp: null,
            effectivePrice: 5.99,
            isOnSale: false,
            profitMargin: 58.26,
            availableStock: 5,
            physicalStock: 5,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: 0,
            options: [new ProductVariationOption(optionId: 1, optionName: 'Size', valueId: 10, valueName: 'Large')],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );

        return new ProductView(
            externalId: 42,
            sku: null,
            gtin: null,
            title: 'Product With Variations',
            description: null,
            slug: 'product-with-variations',
            links: new ProductLinks(
                publicUrl: 'https://example.com/product-with-variations',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/42',
            ),
            price: 9.99,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 9.99,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: [$variation],
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta([$variation], null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale([$variation]),
            parentAvailableStock: 0,
            parentPhysicalStock: 0,
            allVariations: [$variation],
        );
    }

    private function createProduct(
        int $id,
        string $title,
        ?Popularity $popularity = null,
        ?string $discontinued = null,
        ?DateTimeImmutable $preorderDate = null,
        ?string $otherStockStatus = null,
    ): ProductView {
        return new ProductView(
            externalId: $id,
            sku: null,
            gtin: null,
            title: $title,
            description: null,
            slug: 'test-product-' . $id,
            links: new ProductLinks(
                publicUrl: 'https://example.com/test-product-' . $id,
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/' . $id,
            ),
            price: 9.99,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 9.99,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: null,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta([], null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale([]),
            parentAvailableStock: 0,
            parentPhysicalStock: 0,
            allVariations: null,
            popularity: $popularity,
            discontinued: $discontinued,
            preorderDate: $preorderDate,
            otherStockStatus: $otherStockStatus,
        );
    }

    /**
     * @param list<float> $variationPrices
     */
    private function createVariantOnlyProduct(array $variationPrices, int $stockPerVariation = 5): ProductView
    {
        $variations = \array_map(
            static fn(float $price, int $idx): ProductVariationView => new ProductVariationView(
                externalId: 200 + $idx,
                sku: 'VAR-' . $idx,
                gtin: null,
                price: $price,
                costPrice: null,
                salePrice: null,
                rrp: null,
                effectivePrice: $price,
                isOnSale: false,
                profitMargin: null,
                availableStock: $stockPerVariation,
                physicalStock: $stockPerVariation,
                weight: null,
                vatExclusive: false,
                mpn: null,
                imageIndex: null,
                options: [],
                createdAt: new DateTimeImmutable('2024-01-01'),
                updatedAt: new DateTimeImmutable('2024-01-01'),
            ),
            $variationPrices,
            \array_keys($variationPrices),
        );

        $totalStock = $stockPerVariation * \count($variations);

        return new ProductView(
            externalId: 77,
            sku: null,
            gtin: null,
            title: 'Variant-Only Product',
            description: null,
            slug: 'variant-only-product',
            links: new ProductLinks(
                publicUrl: 'https://example.com/variant-only-product',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/77',
            ),
            price: 0.00,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 0.00,
            isOnSale: false,
            profitMargin: null,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: null,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta($variations, null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale($variations),
            parentAvailableStock: 0,
            parentPhysicalStock: 0,
            allVariations: $variations,
            stock: new ProductStock(
                quantity: null,
                available: null,
                inOrder: null,
                due: null,
                jit: false,
                aggregateAvailable: $totalStock,
                aggregatePhysical: $totalStock,
            ),
        );
    }
}
