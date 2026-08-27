<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\MarginTierAssignmentDTO;
use App\Application\Catalog\Enums\CustomLabelField;
use App\Application\Catalog\Enums\MarginTier;
use App\Application\Catalog\UseCases\AbstractDriftSyncUseCase;
use App\Application\Catalog\UseCases\SyncMarginTierLabelUseCase;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncMarginTierLabelUseCase::class)]
#[CoversClass(AbstractDriftSyncUseCase::class)]
final class SyncMarginTierLabelUseCaseTest extends TestCase
{
    private ProductViewQueryRepositoryInterface&MockInterface $productViewQueryRepo;

    private CatalogSyncDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SyncMarginTierLabelUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productViewQueryRepo = Mockery::mock(ProductViewQueryRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(CatalogSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncMarginTierLabelUseCase(
            $this->productViewQueryRepo,
            $this->dispatcher,
            $this->logger,
        );
    }

    #[Test]
    public function execute_logs_and_returns_early_when_no_drift_detected(): void
    {
        $this->productViewQueryRepo
            ->shouldReceive('findMarginTierDrift')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: checking for drift');

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: no drift detected');

        $this->dispatcher->shouldNotReceive('dispatchLabelUpdate');

        $this->useCase->execute();
    }

    #[Test]
    public function execute_dispatches_label_updates_with_correct_field_and_value(): void
    {
        $drift = [
            new MarginTierAssignmentDTO(IntId::from(1001), MarginTier::High),
            new MarginTierAssignmentDTO(IntId::from(1002), MarginTier::Low),
        ];

        $this->productViewQueryRepo
            ->shouldReceive('findMarginTierDrift')
            ->once()
            ->andReturn($drift);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: checking for drift');

        $this->dispatcher
            ->shouldReceive('dispatchLabelUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1001),
                CustomLabelField::MarginTier,
                MarginTier::High->value,
            );

        $this->dispatcher
            ->shouldReceive('dispatchLabelUpdate')
            ->once()
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === 1002),
                CustomLabelField::MarginTier,
                MarginTier::Low->value,
            );

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: dispatched drift corrections', [
                'count' => 2,
                'dispatched_high' => 1,
                'dispatched_low' => 1,
            ]);

        $this->useCase->execute();
    }

    #[Test]
    public function execute_produces_per_tier_count_breakdown(): void
    {
        $drift = [
            new MarginTierAssignmentDTO(IntId::from(1001), MarginTier::Standard),
            new MarginTierAssignmentDTO(IntId::from(1002), MarginTier::Standard),
            new MarginTierAssignmentDTO(IntId::from(1003), MarginTier::Unknown),
        ];

        $this->productViewQueryRepo
            ->shouldReceive('findMarginTierDrift')
            ->once()
            ->andReturn($drift);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: checking for drift');

        $this->dispatcher
            ->shouldReceive('dispatchLabelUpdate')
            ->times(3);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('SyncMarginTierLabel: dispatched drift corrections', [
                'count' => 3,
                'dispatched_standard' => 2,
                'dispatched_unknown' => 1,
            ]);

        $this->useCase->execute();
    }
}
