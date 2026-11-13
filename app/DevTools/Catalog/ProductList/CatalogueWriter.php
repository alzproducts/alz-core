<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Shopwired\Models\CategoryModel;
use App\Infrastructure\Shopwired\Repositories\EloquentCategoryRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use RuntimeException;

/**
 * Product and category writes that save back what the database already holds: a webhook upsert, a category webhook
 * and the daily sync's variation replace. Each rewrites real rows, so variations get new UUIDs and timestamps;
 * CatalogueRowsSnapshot puts the rows back afterwards.
 */
final class CatalogueWriter
{
    /** @var list<int> */
    private array $productIds = [];

    private int $nextWebhookIndex = 0;

    public function __construct(
        private readonly EloquentProductRepository $products,
        private readonly EloquentCategoryRepository $categories,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function captureBaseline(): void
    {
        $this->productIds = $this->products->getAllExternalIds();
        \sort($this->productIds);
        $this->nextWebhookIndex = 0;
    }

    /**
     * Upserts the next product in id order with its variations embed, as a product webhook does.
     *
     * @throws RuntimeException
     */
    public function webhookUpsert(): void
    {
        $productId = $this->productIds === [] ? null : ($this->productIds[$this->nextWebhookIndex % \count($this->productIds)] ?? null);
        $this->nextWebhookIndex++;

        if ($productId !== null) {
            $this->products->saveFromWebhook($this->products->getProduct(IntId::from($productId)), ['variations']);
        }
    }

    /**
     * @throws RuntimeException
     */
    public function categoryWebhook(): void
    {
        $category = CategoryModel::query()
            ->where('external_id', ProductListRequestShapes::FILTERED_CATEGORY_ID)
            ->firstOrFail()
            ->toDomain();

        $this->categories->saveFromWebhook($category);
    }

    /**
     * Every product with its variations, loaded up front so a timed replace measures only the writes.
     *
     * @return list<Product>
     *
     * @throws RuntimeException
     */
    public function loadCatalogue(): array
    {
        return \array_map(fn(int $productId): Product => $this->products->getProduct(IntId::from($productId)), $this->productIds);
    }

    /**
     * The daily sync's per-product save, which deletes and re-inserts each product's variations.
     *
     * @param list<Product> $catalogue
     *
     * @throws RuntimeException
     */
    public function replaceVariations(array $catalogue): void
    {
        foreach ($catalogue as $product) {
            $this->products->save($product);
        }
    }
}
