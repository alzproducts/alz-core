<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs against the shared Postgres database inside a rolled-back transaction.
 */
#[CoversClass(EloquentProductRepository::class)]
final class EloquentProductRepositoryUpsertDiagnosticTest extends TestCase
{
    use DatabaseTransactions;

    private const int PRODUCT_ID = 2147470001;

    private const int VARIATION_ID = 2147470031;

    private const string SKU_PREFIX = 'UPSERT-DIAG-';

    private const string LOG_MESSAGE = 'Product upsert diagnostic';

    private ProductRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(ProductRepositoryInterface::class);
        Log::spy();
    }

    #[Test]
    public function save_logs_api_source_with_incoming_and_replaced_values(): void
    {
        // shopwired_updated_at is timestamptz(0): stored values are whole seconds
        $this->insertStoredProduct(price: '40.00', salePrice: '35.00', updatedAt: '2026-11-14 01:10:00+00');
        $incomingAt = new DateTimeImmutable('2026-11-14T01:10:43.500000+00:00');

        $this->repository->save(self::product(price: 45.5, salePrice: 39.99, updatedAt: $incomingAt));

        $context = $this->loggedContext();
        self::assertSame(self::PRODUCT_ID, $context['product_id']);
        self::assertSame('api', $context['source']);
        self::assertSame([
            'price' => 45.5,
            'sale_price' => 39.99,
            'updated_at' => '2026-11-14T01:10:43.500000+00:00',
        ], $context['incoming']);
        self::assertSame(40.0, $context['replaced']['price']);
        self::assertSame(35.0, $context['replaced']['sale_price']);
        self::assertIsString($context['replaced']['shopwired_updated_at']);
        self::assertSame(
            '2026-11-14T01:10:00.000000',
            \mb_substr($context['replaced']['shopwired_updated_at'], 0, 26),
        );
    }

    #[Test]
    public function save_from_webhook_logs_webhook_source(): void
    {
        $this->insertStoredProduct(price: '40.00', salePrice: null, updatedAt: '2026-11-14 01:10:00+00');

        $this->repository->saveFromWebhook(self::product(price: 45.5, salePrice: null), []);

        $context = $this->loggedContext();
        self::assertSame('webhook', $context['source']);
        self::assertSame(45.5, $context['incoming']['price']);
        self::assertNull($context['incoming']['sale_price']);
        self::assertSame(40.0, $context['replaced']['price']);
        self::assertNull($context['replaced']['sale_price']);
    }

    #[Test]
    public function save_with_variations_lists_each_synced_variation(): void
    {
        $this->insertStoredProduct(price: '40.00', salePrice: null, updatedAt: '2026-11-14 01:10:00+00');
        $variation = new ProductVariation(
            id: self::VARIATION_ID,
            productExternalId: self::PRODUCT_ID,
            sku: self::SKU_PREFIX . 'V1',
            price: 12.5,
            costPrice: null,
            salePrice: 10.0,
            stock: 0,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );

        $this->repository->save(self::product(price: 45.5, salePrice: null, variations: [$variation]));

        $context = $this->loggedContext();
        self::assertTrue($context['variations_synced']);
        self::assertSame([[
            'id' => self::VARIATION_ID,
            'sku' => self::SKU_PREFIX . 'V1',
            'price' => 12.5,
            'sale_price' => 10.0,
        ]], $context['variations']);
    }

    #[Test]
    public function save_without_variations_records_that_variations_were_not_synced(): void
    {
        $this->insertStoredProduct(price: '40.00', salePrice: null, updatedAt: '2026-11-14 01:10:00+00');

        $this->repository->save(self::product(price: 45.5, salePrice: null, variations: null));

        $context = $this->loggedContext();
        self::assertFalse($context['variations_synced']);
        self::assertNull($context['variations']);
    }

    #[Test]
    public function save_with_no_stored_row_logs_null_replaced_values_and_inserts_the_row(): void
    {
        $this->repository->save(self::product(price: 45.5, salePrice: 39.99));

        $context = $this->loggedContext();
        self::assertSame([
            'price' => null,
            'sale_price' => null,
            'shopwired_updated_at' => null,
        ], $context['replaced']);

        $row = DB::table('shopwired.products')->where('external_id', self::PRODUCT_ID)->first(['price', 'sale_price']);
        self::assertNotNull($row);
        self::assertEquals(45.5, $row->price);
        self::assertEquals(39.99, $row->sale_price);
    }

    /**
     * @return array<string, mixed>
     */
    private function loggedContext(): array
    {
        $captured = [];
        Log::shouldHaveReceived('info')
            ->with(self::LOG_MESSAGE, Mockery::on(static function (array $context) use (&$captured): bool {
                $captured[] = $context;

                return true;
            }))
            ->once();
        // intentional: Mockery runs the matcher more than once per call; once() above pins the call count
        self::assertNotEmpty($captured);

        /** @var array<string, mixed> */
        return $captured[0];
    }

    private function insertStoredProduct(string $price, ?string $salePrice, string $updatedAt): void
    {
        $now = \now();
        DB::table('shopwired.products')->insert([
            'external_id' => self::PRODUCT_ID,
            'sku' => self::SKU_PREFIX . 'P1',
            'title' => 'Upsert diagnostic product',
            'slug' => 'upsert-diag-p1',
            'url' => '/upsert-diag-p1',
            'price' => $price,
            'sale_price' => $salePrice,
            'stock' => 0,
            'is_active' => true,
            'vat_exclusive' => false,
            'category_ids' => \json_encode([]),
            'shopwired_created_at' => $now,
            'shopwired_updated_at' => $updatedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param list<ProductVariation>|null $variations
     */
    private static function product(
        float $price,
        ?float $salePrice,
        ?DateTimeImmutable $updatedAt = null,
        ?array $variations = null,
    ): Product {
        $now = new DateTimeImmutable();

        return new Product(
            id: self::PRODUCT_ID,
            sku: self::SKU_PREFIX . 'P1',
            gtin: null,
            title: 'Upsert diagnostic product',
            description: null,
            slug: 'upsert-diag-p1',
            url: '/upsert-diag-p1',
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            comparePrice: null,
            stock: 0,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: CustomFieldValueList::empty(),
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: $now,
            updatedAt: $updatedAt ?? $now,
        );
    }
}
