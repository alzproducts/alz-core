<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteBrandUseCase;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(DeleteBrandUseCase::class)]
final class DeleteBrandUseCaseTest extends TestCase
{
    private BrandRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private DeleteBrandUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(BrandRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new DeleteBrandUseCase(
            brandRepository: $this->repository,
            logger: $this->logger,
        );
    }

    #[Test]
    public function it_deletes_the_brand(): void
    {
        $brandId = IntId::from(7);

        $this->repository->shouldReceive('deleteByExternalId')->once()->with($brandId);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Brand deleted via webhook', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, brandId: $brandId);
    }

    #[Test]
    public function it_is_idempotent_when_brand_is_already_deleted(): void
    {
        $brandId = IntId::from(7);

        $this->repository->shouldReceive('deleteByExternalId')
            ->once()
            ->andThrow(new RecordNotFoundException('Brand', 7));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing brand delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Brand already deleted — skipping', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, brandId: $brandId);
    }
}
