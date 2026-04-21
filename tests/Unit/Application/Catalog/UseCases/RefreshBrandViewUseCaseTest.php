<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\RefreshBrandViewUseCase;
use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\ValueObjects\Brand;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(RefreshBrandViewUseCase::class)]
final class RefreshBrandViewUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;


    private BrandClientInterface&MockInterface $client;

    private BrandRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private RefreshBrandViewUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(BrandClientInterface::class);
        $this->repository = Mockery::mock(BrandRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new RefreshBrandViewUseCase(
            $this->client,
            $this->repository,
            $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function fetches_brand_by_id_and_saves_the_result(): void
    {
        $brand = $this->makeBrand(42);

        $this->client
            ->shouldReceive('getBrandById')
            ->once()
            ->with(42)
            ->andReturn($brand);

        $this->repository
            ->shouldReceive('save')
            ->once()
            ->with($brand);

        $this->useCase->execute(IntId::from(42));
    }

    #[Test]
    public function logs_start_and_completion_with_brand_id(): void
    {
        $brand = $this->makeBrand(9);

        $this->client->shouldReceive('getBrandById')->andReturn($brand);
        $this->repository->shouldReceive('save');

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Refreshing brand', ['brand_id' => 9]);
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Brand refresh complete', ['brand_id' => 9]);

        $useCase = new RefreshBrandViewUseCase(
            $this->client,
            $this->repository,
            $this->logger,
        );

        $useCase->execute(IntId::from(9));
    }

    private function makeBrand(int $id): Brand
    {
        return new Brand(
            id: $id,
            createdAt: new DateTimeImmutable('2024-01-01'),
            title: 'Test Brand',
            description: null,
            slug: 'test-brand',
            url: 'https://example.com/brand/test-brand',
            active: true,
            featured: false,
            sortOrder: 0,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );
    }
}
