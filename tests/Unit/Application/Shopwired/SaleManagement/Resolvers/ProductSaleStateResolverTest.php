<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\Resolvers;

use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductSaleStateResolver::class)]
final class ProductSaleStateResolverTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 999;

    private ProductSaleStateResolver $specification;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->specification = new ProductSaleStateResolver(
            saleCategoryId: self::SALE_CATEGORY_ID,
        );
    }

    #[Test]
    public function on_sale_and_in_sale_category_needs_no_correction(): void
    {
        $view = self::createView(hasAnySale: true, categoryIds: [self::SALE_CATEGORY_ID]);

        $result = $this->specification->evaluate($view);

        self::assertTrue($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    #[Test]
    public function on_sale_but_missing_sale_category_needs_add(): void
    {
        $view = self::createView(hasAnySale: true, categoryIds: [100, 200]);

        $result = $this->specification->evaluate($view);

        self::assertTrue($result->shouldBeOnSale);
        self::assertTrue($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    #[Test]
    public function not_on_sale_but_still_in_sale_category_needs_remove(): void
    {
        $view = self::createView(hasAnySale: false, categoryIds: [self::SALE_CATEGORY_ID]);

        $result = $this->specification->evaluate($view);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertTrue($result->needsRemoveFromSale);
    }

    #[Test]
    public function not_on_sale_and_not_in_sale_category_needs_no_correction(): void
    {
        $view = self::createView(hasAnySale: false, categoryIds: [100]);

        $result = $this->specification->evaluate($view);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    #[Test]
    public function result_carries_product_id_from_view(): void
    {
        $view = self::createView(productId: 4242, hasAnySale: true, categoryIds: [self::SALE_CATEGORY_ID]);

        $result = $this->specification->evaluate($view);

        self::assertSame(4242, $result->productId->value);
    }

    /**
     * @param list<int> $categoryIds
     */
    private static function createView(
        bool $hasAnySale,
        array $categoryIds,
        int $productId = 1,
    ): ProductView {
        $view = Mockery::mock(ProductView::class);
        $view->id = IntId::from($productId);
        $view->hasAnySale = $hasAnySale;
        $view->categoryIds = \array_map(static fn(int $id): IntId => IntId::from($id), $categoryIds);

        $view->shouldReceive('isInCategory')
            ->andReturnUsing(static fn(IntId $target): bool => \in_array($target->value, $categoryIds, true));

        return $view;
    }
}
