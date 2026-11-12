<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\Responses;

use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Presentation\Http\Api\Resources\ProductResource;
use App\Presentation\Http\Api\Responses\ProductListResponseFactory;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The raw assembly must answer exactly what `ProductResource::collection()` over the same views and
 * paginator would, whether each product is rendered now or spliced in from a stored body.
 */
#[CoversNothing]
final class ProductListResponseFactoryTest extends TestCase
{
    private const string PRODUCT_A = '1a8f3c1e-5b7d-4e2a-9c6f-1d3b5a7e9f21';

    private const string PRODUCT_B = '2b8f3c1e-5b7d-4e2a-9c6f-1d3b5a7e9f21';

    private const int TOTAL = 5;

    private const int PER_PAGE = 2;

    private const int CURRENT_PAGE = 2;

    private Request $request;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = Request::create('https://alz.test/api/products?per_page=2&page=2&sort=title&direction=asc');
        $this->app->instance('request', $this->request);
    }

    #[Test]
    public function a_page_of_misses_matches_the_resource_collection_and_returns_every_rendered_body(): void
    {
        [$viewA, $viewB] = [self::product(41, 'Alpha'), self::product(42, 'Beta')];
        $page = self::page([
            new ProductListEntryDTO(self::PRODUCT_A, 'fp-a', null, $viewA),
            new ProductListEntryDTO(self::PRODUCT_B, 'fp-b', null, $viewB),
        ]);

        $result = ProductListResponseFactory::make($page, $this->request);

        self::assertSame($this->expectedBody([$viewA, $viewB]), $result->response->getContent());
        self::assertEquals([
            new ProductListFragmentDTO(self::PRODUCT_A, 'stock', 'fp-a', self::rendered($viewA, $this->request)),
            new ProductListFragmentDTO(self::PRODUCT_B, 'stock', 'fp-b', self::rendered($viewB, $this->request)),
        ], $result->renderedFragments);
    }

    #[Test]
    public function a_stored_body_is_spliced_in_verbatim_and_only_the_miss_is_returned_for_storing(): void
    {
        [$viewA, $viewB] = [self::product(41, 'Alpha'), self::product(42, 'Beta')];
        $page = self::page([
            new ProductListEntryDTO(self::PRODUCT_A, 'fp-a', self::rendered($viewA, $this->request), null),
            new ProductListEntryDTO(self::PRODUCT_B, 'fp-b', null, $viewB),
        ]);

        $result = ProductListResponseFactory::make($page, $this->request);

        self::assertSame($this->expectedBody([$viewA, $viewB]), $result->response->getContent());
        self::assertEquals(
            [new ProductListFragmentDTO(self::PRODUCT_B, 'stock', 'fp-b', self::rendered($viewB, $this->request))],
            $result->renderedFragments,
        );
    }

    /**
     * @param list<ProductView> $views
     */
    private function expectedBody(array $views): string|false
    {
        $paginator = (new LengthAwarePaginator(
            items: $views,
            total: self::TOTAL,
            perPage: self::PER_PAGE,
            currentPage: self::CURRENT_PAGE,
        ))->withQueryString();

        return ProductResource::collection($paginator)->toResponse($this->request)->getContent();
    }

    private static function rendered(ProductView $view, Request $request): string
    {
        return ProductListResponseFactory::render(new ProductListEntryDTO('unused', 'unused', null, $view), $request);
    }

    /**
     * @param list<ProductListEntryDTO> $entries
     */
    private static function page(array $entries): ProductListPageResult
    {
        return new ProductListPageResult(
            total: self::TOTAL,
            perPage: self::PER_PAGE,
            currentPage: self::CURRENT_PAGE,
            shape: 'stock',
            entries: $entries,
        );
    }

    private static function product(int $externalId, string $title): ProductView
    {
        $variation = new ProductVariationView(
            externalId: $externalId * 10,
            sku: 'VAR-' . $externalId,
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
            updatedAt: new DateTimeImmutable('2024-01-02'),
        );

        return new ProductView(
            externalId: $externalId,
            sku: 'SKU-' . $externalId,
            gtin: null,
            title: $title . ' "quoted" / café',
            description: null,
            slug: 'product-' . $externalId,
            links: new ProductLinks(
                publicUrl: 'https://example.com/product-' . $externalId,
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/' . $externalId,
            ),
            price: 9.99,
            costPrice: 4.0,
            salePrice: null,
            rrp: null,
            effectivePrice: 9.99,
            isOnSale: false,
            profitMargin: 59.96,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [3, 7],
            variations: [$variation],
            images: [],
            customFields: CustomFieldValueList::empty(),
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-02'),
            meta: new ProductViewMeta([$variation], null, null),
            hasAnySale: false,
            parentAvailableStock: 0,
            parentPhysicalStock: 0,
            allVariations: [$variation],
        );
    }
}
