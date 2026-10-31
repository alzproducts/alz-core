<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\ProductListFreshness;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryUpdateClientInterface;
use App\Application\Shopwired\UseCases\SyncCategoriesUseCase;
use App\Domain\Catalog\Category\ValueObjects\Category;
use DateTimeImmutable;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A category write changes `main_category_ids` of every product under it, not just rows keyed on
 * the written category: the product sits in child C2 and only parent C1 is written.
 *
 * The category PUT routes have no test of their own: they only call ShopWired, and the local
 * change they cause arrives through the webhook and refresh paths tested here.
 */
#[CoversNothing]
final class CategoryWritersFreshnessTest extends ProductListFreshnessTestCase
{
    private const int PARENT = 1;

    private const int CHILD = 2;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mock(CategoryUpdateClientInterface::class);
        $this->insertProduct(0, ['category_ids' => \json_encode([self::categoryExternalId(self::CHILD)])]);
        $this->insertCategory(self::CHILD, ['parent_ids' => \json_encode([self::categoryExternalId(self::PARENT)])]);
    }

    #[Test]
    public function category_webhook_on_a_parent_fans_out_to_products_in_its_children(): void
    {
        $this->insertCategory(self::PARENT);
        [$before, $fingerprint] = $this->warm(0, self::CHILD);
        self::assertSame([], $before['main_category_ids']);

        $this->postWebhook('categories', 'category.updated', self::categoryExternalId(self::PARENT), [
            'object' => self::categoryPayload(self::PARENT, ['custom_fields' => ['is_main_category' => true]]),
        ]);

        self::assertSame([self::categoryExternalId(self::PARENT)], $this->listedProduct(0, self::CHILD)['main_category_ids']);
        self::assertNotSame($fingerprint, $this->fragmentFingerprint(0));
    }

    #[Test]
    public function category_delete_webhook_is_listed_on_the_next_request(): void
    {
        $this->insertCategory(self::PARENT, ['custom_fields' => \json_encode(['is_main_category' => true])]);
        [$before] = $this->warm(0, self::CHILD);
        self::assertSame([self::categoryExternalId(self::PARENT)], $before['main_category_ids']);

        $this->postWebhook('categories', 'category.deleted', self::categoryExternalId(self::PARENT), []);

        self::assertSame([], $this->listedProduct(0, self::CHILD)['main_category_ids']);
    }

    #[Test]
    public function category_refresh_route_is_listed_on_the_next_request(): void
    {
        $this->insertCategory(self::PARENT);
        $this->warm(0, self::CHILD);
        $this->mock(CategoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('getCategoryById')->with(self::categoryExternalId(self::PARENT))
                ->andReturn(self::domainCategory(self::PARENT, [], ['is_main_category' => true]));
        });

        $this->postJson('/api/categories/' . self::categoryExternalId(self::PARENT) . '/refresh')->assertSuccessful();

        self::assertSame([self::categoryExternalId(self::PARENT)], $this->listedProduct(0, self::CHILD)['main_category_ids']);
    }

    #[Test]
    public function scheduled_category_sync_is_listed_on_the_next_request(): void
    {
        $this->insertCategory(self::PARENT);
        $this->warm(0, self::CHILD);
        $this->mock(CategoryClientInterface::class, static function (MockInterface $mock): void {
            $mock->expects('listAllCategories')->andReturn([
                self::domainCategory(self::PARENT, [], ['is_main_category' => true]),
                self::domainCategory(self::CHILD, [self::categoryExternalId(self::PARENT)], []),
            ]);
        });

        $this->app->make(SyncCategoriesUseCase::class)->execute();

        self::assertSame([self::categoryExternalId(self::PARENT)], $this->listedProduct(0, self::CHILD)['main_category_ids']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function categoryPayload(int $n, array $overrides = []): array
    {
        return [
            'id' => self::categoryExternalId($n),
            'created_at' => \now()->format(\DATE_ATOM),
            'title' => "Freshness category {$n}",
            'description' => null,
            'description2' => null,
            'slug' => "fresh-cat-c{$n}",
            'url' => "/fresh-cat-c{$n}",
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'meta_no_index' => false,
            ...$overrides,
        ];
    }

    /**
     * @param list<int> $parentIds
     * @param array<string, mixed> $customFields
     */
    private static function domainCategory(int $n, array $parentIds, array $customFields): Category
    {
        return new Category(
            id: self::categoryExternalId($n),
            createdAt: new DateTimeImmutable(),
            title: "Freshness category {$n}",
            description: null,
            description2: null,
            slug: "fresh-cat-c{$n}",
            url: "/fresh-cat-c{$n}",
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
            parentIds: $parentIds,
            customFields: $customFields,
        );
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480100;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-CAT-';
    }
}
