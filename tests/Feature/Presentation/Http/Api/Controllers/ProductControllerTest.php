<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Presentation\Http\Api\Controllers\ProductController;
use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use App\Presentation\Http\Middleware\EnsureUserApprovedMiddleware;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(ProductController::class)]
final class ProductControllerTest extends TestCase
{
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
        $dto = PaginatedListDTO::fromPage(items: [$product], total: 1, perPage: 50, currentPage: 1);

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
        $dto = PaginatedListDTO::fromPage(items: [], total: 0, perPage: 50, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(50, 1, ['variations'])
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products?include=variations');

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
        $response = $this->asApprovedUser()->getJson('/api/products?per_page=501');

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
    public function default_per_page_is_50_and_page_is_1(): void
    {
        $dto = PaginatedListDTO::fromPage(items: [], total: 0, perPage: 50, currentPage: 1);

        $this->productRepository
            ->shouldReceive('paginate')
            ->once()
            ->with(50, 1, [])
            ->andReturn($dto);

        $response = $this->asApprovedUser()->getJson('/api/products');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(50, $body['meta']['per_page']);
        $this->assertSame(1, $body['meta']['current_page']);
    }

    #[Test]
    public function pagination_links_preserve_query_string_parameters(): void
    {
        $items = \array_fill(0, 50, $this->createProduct(id: 1, title: 'Product'));
        $dto = PaginatedListDTO::fromPage(items: $items, total: 200, perPage: 50, currentPage: 1);

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
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Make requests as an approved user, bypassing auth middleware.
     *
     * Replaces ValidateSupabaseJwtMiddleware and EnsureUserApprovedMiddleware
     * with a stub that injects a pre-built AuthenticatedUser into request attributes.
     */
    private function asApprovedUser(): static
    {
        $approvedUser = new AuthenticatedUser(
            id: 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
            email: 'test@example.com',
            isApproved: true,
            roleName: 'admin',
        );

        // Bind a stub class that sets authenticated_user and delegates, in place of both auth middleware
        $stub = new class ($approvedUser) {
            public function __construct(private readonly AuthenticatedUser $user) {}

            public function handle(Request $request, Closure $next): Response
            {
                $request->attributes->set('authenticated_user', $this->user);

                return $next($request);
            }
        };

        $this->app->bind(ValidateSupabaseJwtMiddleware::class, static fn() => $stub);
        $this->app->bind(EnsureUserApprovedMiddleware::class, static fn() => new class {
            public function handle(Request $request, Closure $next): Response
            {
                return $next($request);
            }
        });

        return $this;
    }

    private function createProduct(int $id, string $title): Product
    {
        return new Product(
            id: $id,
            sku: 'SKU-' . $id,
            gtin: null,
            title: $title,
            description: null,
            slug: 'test-product-' . $id,
            url: 'https://example.com/test-product-' . $id,
            price: 9.99,
            costPrice: null,
            salePrice: null,
            comparePrice: null,
            stock: 10,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: null,
            images: [],
            rawCustomFields: [],
            customFields: [],
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
