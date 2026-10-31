<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Catalog\UseCases\CheckProductListFragmentDriftUseCase;
use App\Application\Contracts\Catalog\ProductListFragmentStoreInterface;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Exceptions\ProductListFragmentDriftException;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\TestCase;

#[CoversClass(CheckProductListFragmentDriftUseCase::class)]
final class CheckProductListFragmentDriftUseCaseTest extends TestCase
{
    private ProductListFragmentStoreInterface&MockInterface $store;

    private ProductListReaderInterface&MockInterface $reader;

    private ErrorReporterInterface&MockInterface $errorReporter;

    private CheckProductListFragmentDriftUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Mockery::mock(ProductListFragmentStoreInterface::class);
        $this->reader = Mockery::mock(ProductListReaderInterface::class);
        $this->errorReporter = Mockery::mock(ErrorReporterInterface::class);

        $this->useCase = new CheckProductListFragmentDriftUseCase(
            $this->store,
            $this->reader,
            $this->errorReporter,
            Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    #[Test]
    public function sample_rebuilds_each_shape_with_its_includes_and_pairs_fragments_by_product(): void
    {
        $plain = self::fragment('p1', 'none');
        $stocked = self::fragment('p2', 'inventory,stock');
        $orphan = self::fragment('p3', 'inventory,stock');
        $this->store->expects('sample')->with(50)->andReturn([$plain, $stocked, $orphan]);

        $plainEntry = self::entry('p1');
        $stockedEntry = self::entry('p2');
        $this->reader->expects('rebuild')->with(['p1'], [])->andReturn([$plainEntry]);
        $this->reader->expects('rebuild')
            ->with(['p2', 'p3'], [ProductInclude::Inventory, ProductInclude::Stock])
            ->andReturn([$stockedEntry]);

        $samples = $this->useCase->sample(50);

        self::assertCount(2, $samples);
        self::assertSame($plain, $samples[0]->stored);
        self::assertSame($plainEntry, $samples[0]->rebuilt);
        self::assertSame($stocked, $samples[1]->stored);
        self::assertSame($stockedEntry, $samples[1]->rebuilt);
    }

    #[Test]
    public function sample_rejects_a_shape_naming_an_unknown_include(): void
    {
        $this->store->expects('sample')->andReturn([self::fragment('p1', 'stock,bogus')]);
        $this->reader->expects('rebuild')->never();

        $this->expectException(InvalidEnumValueException::class);

        $this->useCase->sample(50);
    }

    #[Test]
    public function record_drift_reports_each_fragment_and_deletes_them(): void
    {
        $this->errorReporter->expects('report')->with(
            Mockery::on(static fn(mixed $e): bool => $e instanceof ProductListFragmentDriftException && $e->productId === 'p1' && $e->shape === 'none'),
            ['product_id' => 'p1', 'shape' => 'none'],
        );
        $this->errorReporter->expects('report')->with(
            Mockery::on(static fn(mixed $e): bool => $e instanceof ProductListFragmentDriftException && $e->productId === 'p2' && $e->shape === 'stock'),
            ['product_id' => 'p2', 'shape' => 'stock'],
        );
        $this->store->expects('deleteMany')->with([
            ['productId' => 'p1', 'shape' => 'none'],
            ['productId' => 'p2', 'shape' => 'stock'],
        ]);

        $this->useCase->recordDrift([self::fragment('p1', 'none'), self::fragment('p2', 'stock')]);
    }

    #[Test]
    public function record_drift_with_nothing_drifted_reports_and_deletes_nothing(): void
    {
        $this->errorReporter->expects('report')->never();
        $this->store->expects('deleteMany')->never();

        $this->useCase->recordDrift([]);
    }

    #[Test]
    public function delete_orphans_returns_the_store_count(): void
    {
        $this->store->expects('deleteOrphans')->andReturn(3);

        self::assertSame(3, $this->useCase->deleteOrphans());
    }

    private static function fragment(string $productId, string $shape): ProductListFragmentDTO
    {
        return new ProductListFragmentDTO($productId, $shape, 'fp-' . $productId, '{"id":1}');
    }

    private static function entry(string $productId): ProductListEntryDTO
    {
        return new ProductListEntryDTO($productId, 'fp-' . $productId, null, new ReflectionClass(ProductView::class)->newInstanceWithoutConstructor());
    }
}
