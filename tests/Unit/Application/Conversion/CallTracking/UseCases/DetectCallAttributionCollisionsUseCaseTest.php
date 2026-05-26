<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingQueryRepositoryInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Application\Conversion\CallTracking\UseCases\DetectCallAttributionCollisionsUseCase;
use App\Domain\Conversion\CallTracking\Exceptions\CallAttributionCollisionDetectedException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DetectCallAttributionCollisionsUseCase::class)]
final class DetectCallAttributionCollisionsUseCaseTest extends TestCase
{
    private CallTrackingQueryRepositoryInterface&MockInterface $repository;

    private ErrorReporterInterface&MockInterface $errorReporter;

    private LoggerInterface&MockInterface $logger;

    private DetectCallAttributionCollisionsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CallTrackingQueryRepositoryInterface::class);
        $this->errorReporter = Mockery::mock(ErrorReporterInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new DetectCallAttributionCollisionsUseCase(
            $this->repository,
            $this->errorReporter,
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
    public function reports_each_collision_to_error_reporter(): void
    {
        $collisionA = [
            'call_id' => 'call-1',
            'visit_ids' => ['visit-1', 'visit-2'],
            'tracking_number' => '+447900000001',
        ];
        $collisionB = [
            'call_id' => 'call-2',
            'visit_ids' => ['visit-3', 'visit-4', 'visit-5'],
            'tracking_number' => '+447900000002',
        ];

        $this->repository->shouldReceive('findAttributionCollisions')
            ->once()
            ->andReturn([$collisionA, $collisionB]);

        $this->logger->shouldReceive('info')->with('Detecting call attribution collisions')->once();
        $this->logger->shouldReceive('info')
            ->with('Call attribution collision sweep complete', ['collision_count' => 2])
            ->once();

        $this->errorReporter->shouldReceive('report')
            ->once()
            ->withArgs(static fn(CallAttributionCollisionDetectedException $e, array $context): bool => $e->callId === 'call-1'
                    && $e->visitIds === ['visit-1', 'visit-2']
                    && $e->trackingNumber === '+447900000001'
                    && $context === [
                        'call_id' => 'call-1',
                        'visit_ids' => ['visit-1', 'visit-2'],
                        'tracking_number' => '+447900000001',
                    ]);

        $this->errorReporter->shouldReceive('report')
            ->once()
            ->withArgs(static fn(CallAttributionCollisionDetectedException $e, array $context): bool => $e->callId === 'call-2'
                    && $e->visitIds === ['visit-3', 'visit-4', 'visit-5']
                    && $context === [
                        'call_id' => 'call-2',
                        'visit_ids' => ['visit-3', 'visit-4', 'visit-5'],
                        'tracking_number' => '+447900000002',
                    ]);

        $this->useCase->execute();
    }

    #[Test]
    public function does_not_report_when_no_collisions_returned(): void
    {
        $this->repository->shouldReceive('findAttributionCollisions')
            ->once()
            ->andReturn([]);

        $this->logger->shouldReceive('info')->with('Detecting call attribution collisions')->once();
        $this->logger->shouldReceive('info')
            ->with('Call attribution collision sweep complete', ['collision_count' => 0])
            ->once();

        $this->errorReporter->shouldNotReceive('report');

        $this->useCase->execute();
    }
}
