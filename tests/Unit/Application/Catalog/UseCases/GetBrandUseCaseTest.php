<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\GetBrandResult;
use App\Application\Catalog\UseCases\GetBrandUseCase;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandLinks;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(GetBrandUseCase::class)]
final class GetBrandUseCaseTest extends TestCase
{
    private BrandRepositoryInterface&MockInterface $brandRepository;

    private LoggerInterface&MockInterface $logger;

    private GetBrandUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brandRepository = Mockery::mock(BrandRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new GetBrandUseCase(
            $this->brandRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_delegates_to_repository_and_returns_get_brand_result(): void
    {
        $brand = self::buildBrandView();

        $this->brandRepository
            ->shouldReceive('findBrandForApi')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), [])
            ->andReturn($brand);

        $this->logger->shouldReceive('info')->once()->with('Getting brand', ['brand_id' => 42, 'includes' => []]);
        $this->logger->shouldReceive('info')->once()->with('Got brand', ['brand_id' => 42, 'title' => 'Test Brand']);

        $result = $this->useCase->execute(brandId: 42);

        self::assertInstanceOf(GetBrandResult::class, $result);
        self::assertSame($brand, $result->brand);
        self::assertSame([], $result->includes);
    }

    #[Test]
    public function execute_passes_includes_through_to_repository(): void
    {
        $brand = self::buildBrandView();
        $includes = [BrandInclude::CustomFields, BrandInclude::Description];

        $this->brandRepository
            ->shouldReceive('findBrandForApi')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), $includes)
            ->andReturn($brand);

        $this->logger->shouldReceive('info')->once()->with('Getting brand', ['brand_id' => 42, 'includes' => ['custom_fields', 'description']]);
        $this->logger->shouldReceive('info')->once()->with('Got brand', ['brand_id' => 42, 'title' => 'Test Brand']);

        $result = $this->useCase->execute(brandId: 42, includes: $includes);

        self::assertSame($includes, $result->includes);
    }

    #[Test]
    public function execute_logs_getting_and_got_messages_with_correct_context(): void
    {
        $brand = self::buildBrandView();

        $this->brandRepository
            ->shouldReceive('findBrandForApi')
            ->once()
            ->andReturn($brand);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Getting brand', ['brand_id' => 42, 'includes' => []]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Got brand', ['brand_id' => 42, 'title' => 'Test Brand']);

        $this->useCase->execute(brandId: 42);
    }

    private static function buildBrandView(): BrandView
    {
        return new BrandView(
            id: IntId::from(42),
            title: 'Test Brand',
            slug: 'test-brand',
            links: new BrandLinks(
                publicUrl: '/brands/test-brand',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-brand/42',
            ),
            active: true,
            featured: false,
            sortOrder: 1,
            metaTitle: null,
            metaDescription: null,
            image: null,
            createdAt: new DateTimeImmutable('2025-01-01'),
        );
    }
}
