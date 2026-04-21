<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Contracts\Shopwired\BrandUpdateClientInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Catalog\Brand\ValueObjects\Brand;
use App\Presentation\Http\Api\Controllers\BrandUpdateController;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(BrandUpdateController::class)]
final class BrandUpdateControllerRefreshTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private BrandClientInterface&MockInterface $client;

    private BrandRepositoryInterface&MockInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(BrandClientInterface::class);
        $this->repository = Mockery::mock(BrandRepositoryInterface::class);

        $this->app->instance(BrandClientInterface::class, $this->client);
        $this->app->instance(BrandRepositoryInterface::class, $this->repository);

        // Controller constructor also pulls in UpdateBrandFieldsUseCase +
        // UpdateBrandCustomFieldsUseCase — both resolve BrandUpdateClientInterface
        // whose binding calls ShopwiredClientFactory::getTransport(). Bind an empty
        // mock so the container doesn't trigger real config reads.
        $this->app->instance(BrandUpdateClientInterface::class, Mockery::mock(BrandUpdateClientInterface::class));
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function unauthenticated_single_refresh_returns_401(): void
    {
        $response = $this->postJson('/api/brands/42/refresh');

        $response->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_refresh_all_returns_401(): void
    {
        $response = $this->postJson('/api/brands/refresh');

        $response->assertStatus(401);
    }

    #[Test]
    public function single_refresh_fetches_by_id_saves_and_returns_204(): void
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

        $response = $this->asApprovedUser()->postJson('/api/brands/42/refresh');

        $response->assertStatus(204);
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function non_numeric_single_refresh_id_does_not_match_route(): void
    {
        $this->client->shouldNotReceive('getBrandById');
        $this->repository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->postJson('/api/brands/abc/refresh');

        $response->assertStatus(404);
    }

    #[Test]
    public function refresh_all_calls_list_all_and_save_many_and_returns_204(): void
    {
        $brands = [$this->makeBrand(1), $this->makeBrand(2)];

        $this->client
            ->shouldReceive('listAllBrands')
            ->once()
            ->andReturn($brands);

        $this->repository
            ->shouldReceive('saveMany')
            ->once()
            ->with($brands)
            ->andReturn(new SaveManyResult(
                succeeded: 2,
                failed: 0,
                failedReferences: [],
            ));

        $response = $this->asApprovedUser()->postJson('/api/brands/refresh');

        $response->assertStatus(204);
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function refresh_all_surfaces_500_when_use_case_reports_zero_rows(): void
    {
        $this->client
            ->shouldReceive('listAllBrands')
            ->once()
            ->andReturn([]);

        $this->repository->shouldNotReceive('saveMany');

        $response = $this->asApprovedUser()->postJson('/api/brands/refresh');

        $response->assertStatus(500);
    }

    private function makeBrand(int $id): Brand
    {
        return new Brand(
            id: $id,
            createdAt: new DateTimeImmutable('2024-01-01'),
            title: 'Test Brand ' . $id,
            description: null,
            slug: 'test-brand-' . $id,
            url: 'https://example.com/brand/test-brand-' . $id,
            active: true,
            featured: false,
            sortOrder: 0,
            metaTitle: null,
            metaKeywords: null,
            metaDescription: null,
        );
    }
}
