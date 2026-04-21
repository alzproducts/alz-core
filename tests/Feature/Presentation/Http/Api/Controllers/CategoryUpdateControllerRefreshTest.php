<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Presentation\Http\Api\Controllers\CategoryUpdateController;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(CategoryUpdateController::class)]
final class CategoryUpdateControllerRefreshTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private CategoryClientInterface&MockInterface $client;

    private CategoryRepositoryInterface&MockInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(CategoryClientInterface::class);
        $this->repository = Mockery::mock(CategoryRepositoryInterface::class);

        $this->app->instance(CategoryClientInterface::class, $this->client);
        $this->app->instance(CategoryRepositoryInterface::class, $this->repository);
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
        $response = $this->postJson('/api/categories/42/refresh');

        $response->assertStatus(401);
    }

    #[Test]
    public function unauthenticated_refresh_all_returns_401(): void
    {
        $response = $this->postJson('/api/categories/refresh');

        $response->assertStatus(401);
    }

    #[Test]
    public function single_refresh_fetches_by_id_saves_and_returns_204(): void
    {
        $category = $this->makeCategory(42);

        $this->client
            ->shouldReceive('getCategoryById')
            ->once()
            ->with(42)
            ->andReturn($category);

        $this->repository
            ->shouldReceive('save')
            ->once()
            ->with($category);

        $response = $this->asApprovedUser()->postJson('/api/categories/42/refresh');

        $response->assertStatus(204);
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function non_numeric_single_refresh_id_does_not_match_route(): void
    {
        $this->client->shouldNotReceive('getCategoryById');
        $this->repository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->postJson('/api/categories/abc/refresh');

        $response->assertStatus(404);
    }

    #[Test]
    public function refresh_all_calls_list_all_and_save_many_and_returns_204(): void
    {
        $categories = [$this->makeCategory(1), $this->makeCategory(2)];

        $this->client
            ->shouldReceive('listAllCategories')
            ->once()
            ->andReturn($categories);

        $this->repository
            ->shouldReceive('saveMany')
            ->once()
            ->with($categories)
            ->andReturn(new SaveManyResult(
                succeeded: 2,
                failed: 0,
                failedReferences: [],
            ));

        $response = $this->asApprovedUser()->postJson('/api/categories/refresh');

        $response->assertStatus(204);
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function refresh_all_surfaces_500_when_use_case_reports_zero_rows(): void
    {
        $this->client
            ->shouldReceive('listAllCategories')
            ->once()
            ->andReturn([]);

        $this->repository->shouldNotReceive('saveMany');

        // SyncCategoriesUseCase throws RuntimeException on zero rows; Laravel's
        // default exception handler renders it as a 500.
        $response = $this->asApprovedUser()->postJson('/api/categories/refresh');

        $response->assertStatus(500);
    }

    private function makeCategory(int $id): Category
    {
        return new Category(
            id: $id,
            createdAt: new DateTimeImmutable('2024-01-01'),
            title: 'Test Category ' . $id,
            description: null,
            description2: null,
            slug: 'test-category-' . $id,
            url: 'https://example.com/category/test-category-' . $id,
            active: true,
            featured: false,
            tradeOnly: false,
            sortOrder: 0,
            metaTitle: null,
            metaDescription: null,
            metaKeywords: null,
            metaNoIndex: false,
        );
    }
}
