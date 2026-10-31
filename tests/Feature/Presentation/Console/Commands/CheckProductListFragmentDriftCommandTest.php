<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Contracts\Catalog\ProductListFragmentStoreInterface;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Catalog\Product\Exceptions\ProductListFragmentDriftException;
use App\Presentation\Console\Commands\CheckProductListFragmentDriftCommand;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Presentation\Http\Api\ProductListFreshness\ProductListFreshnessTestCase;

/**
 * Runs the drift check against committed fragments: one tampered (fingerprint kept, body changed),
 * one untouched, and one whose product does not exist.
 */
#[CoversClass(CheckProductListFragmentDriftCommand::class)]
final class CheckProductListFragmentDriftCommandTest extends ProductListFreshnessTestCase
{
    private const string SHAPE = 'inventory,stock,variations';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->insertCategory(0);
        $this->insertProduct(0);
        $this->insertProduct(1);
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::table('catalog.product_list_fragments')->where('product_id', self::orphanId())->delete();

        parent::tearDown();
    }

    #[Test]
    public function reports_and_deletes_the_tampered_fragment_and_deletes_the_orphan(): void
    {
        $this->warm(0);
        self::assertNotNull($this->fragmentFingerprint(1), 'Listing must store the untouched product fragment');
        $tampered = $this->productUuid(0);
        $untouched = $this->productUuid(1);
        $this->tamperTitle($tampered);
        $this->insertOrphanFragment();
        $this->pinSampleTo([$tampered, $untouched, self::orphanId()]);

        $this->mock(ErrorReporterInterface::class, static function (MockInterface $mock) use ($tampered): void {
            $mock->expects('report')->once()->withArgs(
                static fn(mixed $exception, array $context): bool => $exception instanceof ProductListFragmentDriftException
                    && $exception->productId === $tampered
                    && $exception->shape === self::SHAPE
                    && $context === ['product_id' => $tampered, 'shape' => self::SHAPE],
            );
        });

        $this->artisan('catalog:check-list-fragment-drift')
            ->expectsOutputToContain("Drifted fragment deleted: product {$tampered}, shape " . self::SHAPE)
            ->expectsOutputToContain('Checked 2 fragments: 1 drifted,')
            ->assertSuccessful();

        self::assertFalse($this->fragmentExists($tampered), 'The drifted fragment must be deleted');
        self::assertFalse($this->fragmentExists(self::orphanId()), 'The orphaned fragment must be deleted');
        self::assertTrue($this->fragmentExists($untouched), 'A fragment matching its rebuild must be kept');
    }

    #[Override]
    protected static function idBase(): int
    {
        return 2147480900;
    }

    #[Override]
    protected static function skuPrefix(): string
    {
        return 'FRESH-DRIFT-';
    }

    private static function orphanId(): string
    {
        return self::guid(700);
    }

    /**
     * Keeps the stored fingerprint and changes a field the body is built from: what a builder input
     * missing from the fingerprint would leave behind.
     */
    private function tamperTitle(string $productId): void
    {
        $updated = DB::update(
            'UPDATE catalog.product_list_fragments SET body = replace(body, ?, ?) WHERE product_id = ?::uuid AND shape = ?',
            ['"title":"Freshness product 0"', '"title":"Tampered product 0"', $productId, self::SHAPE],
        );
        self::assertSame(1, $updated);
        self::assertStringContainsString('Tampered product 0', $this->fragmentBody($productId));
    }

    private function insertOrphanFragment(): void
    {
        DB::table('catalog.product_list_fragments')->insert([
            'product_id' => self::orphanId(),
            'shape' => self::SHAPE,
            'fingerprint' => \str_repeat('0', 32),
            'body' => '{"id":0}',
        ]);
    }

    /**
     * The real sample is random over every stored fragment; this one returns only the fixture's,
     * read from the table, and delegates everything else to the real store.
     *
     * @param list<string> $productIds
     */
    private function pinSampleTo(array $productIds): void
    {
        // intentional: resolve first so the deferred CatalogServiceProvider's bindings cannot replace the double below
        $this->app->make(ProductListReaderInterface::class);
        $real = $this->app->make(ProductListFragmentStoreInterface::class);

        $double = new readonly class ($real, $productIds) implements ProductListFragmentStoreInterface {
            /** @param list<string> $productIds */
            public function __construct(private ProductListFragmentStoreInterface $real, private array $productIds) {}

            public function storeMany(array $fragments): void
            {
                $this->real->storeMany($fragments);
            }

            public function sample(int $limit): array
            {
                return DB::table('catalog.product_list_fragments')
                    ->whereIn('product_id', $this->productIds)
                    ->orderBy('product_id')
                    ->get()
                    ->map(static fn(object $row): ProductListFragmentDTO => new ProductListFragmentDTO(
                        productId: (string) $row->product_id,
                        shape: (string) $row->shape,
                        fingerprint: (string) $row->fingerprint,
                        body: (string) $row->body,
                    ))
                    ->values()
                    ->all();
            }

            public function deleteMany(array $keys): void
            {
                $this->real->deleteMany($keys);
            }

            public function deleteOrphans(): int
            {
                return $this->real->deleteOrphans();
            }
        };

        $this->app->bind(ProductListFragmentStoreInterface::class, static fn(): ProductListFragmentStoreInterface => $double);
    }

    private function fragmentExists(string $productId): bool
    {
        return DB::table('catalog.product_list_fragments')->where('product_id', $productId)->where('shape', self::SHAPE)->exists();
    }

    private function fragmentBody(string $productId): string
    {
        $body = DB::table('catalog.product_list_fragments')->where('product_id', $productId)->where('shape', self::SHAPE)->value('body');
        self::assertIsString($body);

        return $body;
    }
}
