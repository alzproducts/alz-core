<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteCategoryUseCase;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(DeleteCategoryUseCase::class)]
final class DeleteCategoryUseCaseTest extends TestCase
{
    private CategoryRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private DeleteCategoryUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new DeleteCategoryUseCase(
            categoryRepository: $this->repository,
            logger: $this->logger,
        );
    }

    #[Test]
    public function it_deletes_the_category(): void
    {
        $categoryId = IntId::from(42);

        $this->repository->shouldReceive('deleteByExternalId')->once()->with($categoryId);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Category deleted via webhook', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, categoryId: $categoryId);
    }

    #[Test]
    public function it_is_idempotent_when_category_is_already_deleted(): void
    {
        $categoryId = IntId::from(42);

        $this->repository->shouldReceive('deleteByExternalId')
            ->once()
            ->andThrow(new RecordNotFoundException('Category', 42));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing category delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Category already deleted — skipping', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, categoryId: $categoryId);
    }
}
