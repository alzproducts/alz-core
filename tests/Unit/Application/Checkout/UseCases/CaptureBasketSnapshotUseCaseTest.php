<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Checkout\UseCases;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Application\Checkout\UseCases\CaptureBasketSnapshotUseCase;
use App\Application\Contracts\Checkout\BasketSnapshotRepositoryInterface;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * CaptureBasketSnapshotUseCase Unit Tests.
 *
 * Verifies the use case persists exactly once via the repository, logs the
 * received + persisted milestones (with hashed IP, no PII), and lets
 * repository exceptions bubble up unmodified.
 */
#[CoversNothing]
final class CaptureBasketSnapshotUseCaseTest extends TestCase
{
    private BasketSnapshotRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private CaptureBasketSnapshotUseCase $useCase;

    private const string SNAPSHOT_ID = 'snapshot-uuid-abc';

    private const string IP_ADDRESS = '203.0.113.50';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(BasketSnapshotRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new CaptureBasketSnapshotUseCase(
            repository: $this->repository,
            logger: $this->logger,
        );
    }

    #[Test]
    public function execute_persists_snapshot_via_repository_once(): void
    {
        $snapshot = $this->createSnapshot();

        $this->repository->expects('save')
            ->with($snapshot)
            ->once()
            ->andReturn(self::SNAPSHOT_ID);

        $this->useCase->execute($snapshot);
    }

    #[Test]
    public function execute_logs_received_then_persisted_milestones(): void
    {
        $this->repository->expects('save')
            ->andReturn(self::SNAPSHOT_ID);

        $expectedIpHash = \hash('sha256', self::IP_ADDRESS);

        $this->logger->expects('info')
            ->once()
            ->with('Basket snapshot received', Mockery::on(
                static fn(array $context): bool => $context['ip_hash'] === $expectedIpHash
                    && $context['basket_total'] === '129.99'
                    && $context['has_shipping_method'] === false
                    && $context['has_delivery_date'] === false
                    && $context['has_gift_note'] === false
                    && $context['has_vat_relief'] === false,
            ));

        $this->logger->expects('info')
            ->once()
            ->with('Basket snapshot persisted', ['snapshot_id' => self::SNAPSHOT_ID]);

        $this->useCase->execute($this->createSnapshot());
    }

    #[Test]
    public function execute_does_not_hash_or_otherwise_leak_raw_ip_address(): void
    {
        $this->repository->expects('save')
            ->andReturn(self::SNAPSHOT_ID);

        $rawIp = self::IP_ADDRESS;

        $this->logger->expects('info')
            ->once()
            ->with('Basket snapshot received', Mockery::on(
                static fn(array $context): bool => !\in_array($rawIp, $context, true)
                    && $context['ip_hash'] !== $rawIp,
            ));

        $this->logger->expects('info')
            ->once();

        $this->useCase->execute($this->createSnapshot());
    }

    #[Test]
    public function execute_lets_repository_exception_bubble_up_uncaught(): void
    {
        $exception = new DatabaseOperationFailedException('insert', 'connection lost');

        $this->repository->expects('save')
            ->andThrow($exception);

        $this->expectExceptionObject($exception);

        $this->useCase->execute($this->createSnapshot());
    }

    private function createSnapshot(): BasketSnapshotCommand
    {
        return new BasketSnapshotCommand(
            ipAddress: self::IP_ADDRESS,
            userAgent: 'Mozilla/5.0',
            basketTotal: Money::inclusive(129.99),
        );
    }
}
