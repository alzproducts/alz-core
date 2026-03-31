<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ReviewsIo\UseCases;

use App\Application\Contracts\ReviewsIo\ChangedRatingQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductRatingChange;
use App\Application\ReviewsIo\UseCases\UpdateShopwiredRatingsUseCase;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * UpdateShopwiredRatingsUseCase Unit Tests.
 *
 * Tests the orchestration logic:
 * - Empty changes handling (no products to update)
 * - Successful update flow
 * - Partial failure handling (ResourceNotAvailableException)
 */
#[CoversClass(UpdateShopwiredRatingsUseCase::class)]
final class UpdateShopwiredRatingsUseCaseTest extends TestCase
{
    private ChangedRatingQueryRepositoryInterface&MockInterface $ratingRepository;

    private ProductUpdateClientInterface&MockInterface $updateClient;

    private LoggerInterface&MockInterface $logger;

    private UpdateShopwiredRatingsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ratingRepository = Mockery::mock(ChangedRatingQueryRepositoryInterface::class);
        $this->updateClient = Mockery::mock(ProductUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new UpdateShopwiredRatingsUseCase(
            $this->ratingRepository,
            $this->updateClient,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Changes Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_when_no_products_with_changed_ratings(): void
    {
        $this->ratingRepository
            ->shouldReceive('getProductsWithChangedRatings')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')->with('Starting ShopWired ratings update');
        $this->logger->shouldReceive('info')->with('No products with changed ratings');

        $result = $this->useCase->execute();

        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Update Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_updates_all_products_successfully(): void
    {
        $changes = [
            new ProductRatingChange(IntId::from(1001), '4.5000', 25),
            new ProductRatingChange(IntId::from(1002), '4.8000', 50),
        ];

        $this->ratingRepository
            ->shouldReceive('getProductsWithChangedRatings')
            ->once()
            ->andReturn($changes);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(1001, ['average_rating' => '4.5000', 'num_ratings' => '25']);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(1002, ['average_rating' => '4.8000', 'num_ratings' => '50']);

        $this->logger->shouldReceive('info')->with('Starting ShopWired ratings update');
        $this->logger->shouldReceive('info')->with('Found products with changed ratings', ['count' => 2]);
        $this->logger->shouldReceive('info')->with('ShopWired ratings update completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(2, $result->processed);
        $this->assertSame(2, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Branch (ResourceNotAvailableException)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_resource_not_found_and_continues(): void
    {
        $changes = [
            new ProductRatingChange(IntId::from(1001), '4.5000', 25),
            new ProductRatingChange(IntId::from(9999), '4.8000', 50), // Will fail
            new ProductRatingChange(IntId::from(1003), '4.2000', 10),
        ];

        $this->ratingRepository
            ->shouldReceive('getProductsWithChangedRatings')
            ->once()
            ->andReturn($changes);

        // First product succeeds
        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(1001, ['average_rating' => '4.5000', 'num_ratings' => '25']);

        // Second product not found
        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(9999, ['average_rating' => '4.8000', 'num_ratings' => '50'])
            ->andThrow(new ResourceNotAvailableException('ShopWired', 'Product', '9999'));

        // Third product succeeds
        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(1003, ['average_rating' => '4.2000', 'num_ratings' => '10']);

        $this->logger->shouldReceive('info')->with('Starting ShopWired ratings update');
        $this->logger->shouldReceive('info')->with('Found products with changed ratings', ['count' => 3]);
        $this->logger->shouldReceive('warning')->with('Product not available in ShopWired', ['product_id' => 9999]);
        $this->logger->shouldReceive('info')->with('ShopWired ratings update completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(3, $result->processed);
        $this->assertSame(2, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, $result->failed);
        $this->assertSame([9999], $result->failedProductIds);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Null Rating Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_null_average_rating(): void
    {
        $changes = [
            new ProductRatingChange(IntId::from(1001), null, 0), // No reviews
        ];

        $this->ratingRepository
            ->shouldReceive('getProductsWithChangedRatings')
            ->once()
            ->andReturn($changes);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(1001, ['average_rating' => null, 'num_ratings' => '0']);

        $this->logger->shouldReceive('info')->with('Starting ShopWired ratings update');
        $this->logger->shouldReceive('info')->with('Found products with changed ratings', ['count' => 1]);
        $this->logger->shouldReceive('info')->with('ShopWired ratings update completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->failed);
    }
}
