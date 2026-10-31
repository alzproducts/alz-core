<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ReviewsIo\UseCases;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Application\ReviewsIo\UseCases\SyncProductRatingsUseCase;
use App\Domain\Catalog\Product\ValueObjects\ProductRating;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncProductRatingsUseCase Unit Tests.
 *
 * Tests the orchestration logic:
 * - Empty SKUs throws exception
 * - Single batch (no buffer overflow)
 * - Buffer overflow triggers flush
 * - Partial failure handling
 */
#[CoversClass(SyncProductRatingsUseCase::class)]
final class SyncProductRatingsUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $productRepository;

    private ReviewsIoClientInterface&MockInterface $reviewsIoClient;

    private ProductRatingRepositoryInterface&MockInterface $ratingRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncProductRatingsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->reviewsIoClient = Mockery::mock(ReviewsIoClientInterface::class);
        $this->ratingRepository = Mockery::mock(ProductRatingRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->reviewsIoClient
            ->shouldReceive('acceptsSku')
            ->andReturnUsing(static fn(string $sku): bool => !\str_contains($sku, '&') && \mb_strlen($sku) <= 100)
            ->byDefault();

        $this->useCase = new SyncProductRatingsUseCase(
            $this->productRepository,
            $this->reviewsIoClient,
            $this->ratingRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty SKUs Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_throws_exception_when_no_skus_found(): void
    {
        $this->productRepository
            ->shouldReceive('getAllSkus')
            ->once()
            ->andReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No SKUs found in product catalog - products must be synced first');

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Single Batch Branch (No Buffer Overflow)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_syncs_small_batch_successfully(): void
    {
        $skus = ['SKU-001', 'SKU-002', 'SKU-003'];
        $ratings = [
            new ProductRating('SKU-001', 4.5, 25),
            new ProductRating('SKU-002', 4.8, 50),
            // SKU-003 has no reviews, not returned
        ];

        $this->productRepository
            ->shouldReceive('getAllSkus')
            ->once()
            ->andReturn($skus);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with($skus)
            ->andReturn($ratings);

        $this->ratingRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($ratings)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 3]);
        $this->logger->shouldReceive('debug')->with('Flushing ratings to database', ['count' => 2]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(3, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | API Batching Branch (More than 100 SKUs)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_batches_api_calls_at_100_skus(): void
    {
        // Create 150 SKUs to trigger 2 API batches
        $skus = \array_map(static fn(int $i) => "SKU-{$i}", \range(1, 150));

        $batch1Skus = \array_slice($skus, 0, 100);
        $batch2Skus = \array_slice($skus, 100, 50);

        $batch1Ratings = [new ProductRating('SKU-1', 4.5, 10)];
        $batch2Ratings = [new ProductRating('SKU-101', 4.8, 20)];

        $this->productRepository
            ->shouldReceive('getAllSkus')
            ->once()
            ->andReturn($skus);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with($batch1Skus)
            ->andReturn($batch1Ratings);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with($batch2Skus)
            ->andReturn($batch2Ratings);

        // Both batches flushed together at the end (2 ratings < 1000 buffer)
        $this->ratingRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 150]);
        $this->logger->shouldReceive('debug')->with('Flushing ratings to database', ['count' => 2]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(150, $result->fetched);
        $this->assertSame(2, $result->saved);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_partial_save_failure(): void
    {
        $skus = ['SKU-001', 'SKU-002', 'SKU-003'];
        $ratings = [
            new ProductRating('SKU-001', 4.5, 25),
            new ProductRating('SKU-002', 4.8, 50),
            new ProductRating('SKU-003', 4.2, 10),
        ];

        $this->productRepository
            ->shouldReceive('getAllSkus')
            ->once()
            ->andReturn($skus);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with($skus)
            ->andReturn($ratings);

        $this->ratingRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($ratings)
            ->andReturn(new SaveManyResult(succeeded: 2, failed: 1, failedReferences: ['SKU-002']));

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 3]);
        $this->logger->shouldReceive('debug')->with('Flushing ratings to database', ['count' => 3]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(3, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(1, $result->failed);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | No Reviews Found Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_no_reviews_found(): void
    {
        $skus = ['SKU-001', 'SKU-002'];

        $this->productRepository
            ->shouldReceive('getAllSkus')
            ->once()
            ->andReturn($skus);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with($skus)
            ->andReturn([]); // No products have reviews

        // No saveMany call since buffer is empty

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 2]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(2, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Rejected SKU Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_skips_rejected_sku_and_syncs_remaining_batches(): void
    {
        $validSkus = \array_map(static fn(int $i) => "SKU-{$i}", \range(1, 150));
        $skus = [...\array_slice($validSkus, 0, 50), 'R&D-01', ...\array_slice($validSkus, 50)];

        $this->productRepository->shouldReceive('getAllSkus')->once()->andReturn($skus);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with(\array_slice($validSkus, 0, 100))
            ->andReturn([new ProductRating('SKU-1', 4.5, 10)]);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with(\array_slice($validSkus, 100, 50))
            ->andReturn([new ProductRating('SKU-101', 4.8, 20)]);

        $this->ratingRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 151]);
        $this->logger->shouldReceive('warning')->once()->with('Skipping SKUs rejected by Reviews.io client', [
            'skipped_count' => 1,
            'skipped_skus' => ['R&D-01'],
        ]);
        $this->logger->shouldReceive('debug')->with('Flushing ratings to database', ['count' => 2]);
        $this->logger->shouldReceive('info')->once()->with('Ratings sync completed', [
            'skus_queried' => 150,
            'skus_skipped' => 1,
            'ratings_saved' => 2,
            'failed' => 0,
        ]);

        $result = $this->useCase->execute();

        $this->assertSame(150, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    #[Test]
    public function execute_skips_sku_longer_than_max_length(): void
    {
        $longSku = \str_repeat('A', 101);

        $this->productRepository->shouldReceive('getAllSkus')->once()->andReturn(['SKU-001', $longSku]);

        $this->reviewsIoClient
            ->shouldReceive('getProductRatingBatch')
            ->once()
            ->with(['SKU-001'])
            ->andReturn([]);

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 2]);
        $this->logger->shouldReceive('warning')->once()->with('Skipping SKUs rejected by Reviews.io client', [
            'skipped_count' => 1,
            'skipped_skus' => [$longSku],
        ]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(1, $result->fetched);
        $this->assertSame(0, $result->saved);
    }

    #[Test]
    public function execute_completes_without_api_call_when_every_sku_is_rejected(): void
    {
        $this->productRepository->shouldReceive('getAllSkus')->once()->andReturn(['R&D-01', 'A&B']);

        $this->reviewsIoClient->shouldNotReceive('getProductRatingBatch');
        $this->ratingRepository->shouldNotReceive('saveMany');

        $this->logger->shouldReceive('info')->with('Starting Reviews.io ratings sync', ['total_skus' => 2]);
        $this->logger->shouldReceive('warning')->once()->with('Skipping SKUs rejected by Reviews.io client', [
            'skipped_count' => 2,
            'skipped_skus' => ['R&D-01', 'A&B'],
        ]);
        $this->logger->shouldReceive('info')->with('Ratings sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    #[Test]
    public function execute_caps_logged_skipped_skus_at_fifty(): void
    {
        $rejected = \array_map(static fn(int $i) => "BAD&{$i}", \range(1, 60));

        $this->productRepository->shouldReceive('getAllSkus')->once()->andReturn($rejected);

        $this->logger->shouldReceive('info');
        $this->logger->shouldReceive('warning')->once()->with('Skipping SKUs rejected by Reviews.io client', [
            'skipped_count' => 60,
            'skipped_skus' => \array_slice($rejected, 0, 50),
        ]);

        $this->useCase->execute();
    }
}
