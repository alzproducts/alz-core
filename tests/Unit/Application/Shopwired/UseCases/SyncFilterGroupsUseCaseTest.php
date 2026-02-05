<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\FilterGroupClientInterface;
use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Application\Shopwired\UseCases\SyncFilterGroupsUseCase;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncFilterGroupsUseCase Unit Tests.
 *
 * Per TestingStrategy.md: Test orchestration decisions (early returns,
 * branching logic), not pure delegation.
 *
 * Key branches tested:
 * - Throw RuntimeException when API returns 0 filter groups
 * - Log error when save has failures
 * - Return correct SyncResult
 */
#[CoversClass(SyncFilterGroupsUseCase::class)]
final class SyncFilterGroupsUseCaseTest extends TestCase
{
    private FilterGroupClientInterface&MockInterface $client;

    private FilterGroupRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private SyncFilterGroupsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(FilterGroupClientInterface::class);
        $this->repository = Mockery::mock(FilterGroupRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncFilterGroupsUseCase(
            $this->client,
            $this->repository,
            $this->logger,
        );
    }

    // ========================================================================
    // Zero Filter Groups Branch
    // ========================================================================

    #[Test]
    public function it_throws_runtime_exception_when_api_returns_zero_groups(): void
    {
        $this->client->shouldReceive('listAll')
            ->once()
            ->andReturn([]);

        // Repository should NOT be called
        $this->repository->shouldNotReceive('saveMany');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ShopWired returned zero filter groups - this is unexpected');

        $this->useCase->execute();
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_syncs_filter_groups_successfully(): void
    {
        $definitions = [
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
            new FilterGroupDefinition(id: 2, title: 'Colour', optionNo: 2, sortOrder: 1),
        ];

        $this->client->shouldReceive('listAll')
            ->once()
            ->andReturn($definitions);

        $this->repository->shouldReceive('saveMany')
            ->once()
            ->with($definitions)
            ->andReturn(SaveManyResult::success(2));

        $result = $this->useCase->execute();

        self::assertSame(2, $result->fetched);
        self::assertSame(2, $result->saved);
        self::assertSame(0, $result->failed);
        self::assertSame([], $result->failedReferences);
    }

    // ========================================================================
    // Partial Failure Branch
    // ========================================================================

    #[Test]
    public function it_logs_error_when_save_has_failures(): void
    {
        $definitions = [
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
            new FilterGroupDefinition(id: 2, title: 'Colour', optionNo: 2, sortOrder: 1),
        ];

        $this->client->shouldReceive('listAll')
            ->once()
            ->andReturn($definitions);

        // One save fails
        $this->repository->shouldReceive('saveMany')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 1, failedReferences: [2]));

        // Should log error for partial failure
        $this->logger->shouldReceive('error')
            ->once()
            ->with('Failed to save some filter group definitions to database', Mockery::hasKey('failed_count'));

        $result = $this->useCase->execute();

        self::assertSame(2, $result->fetched);
        self::assertSame(1, $result->saved);
        self::assertSame(1, $result->failed);
        self::assertSame([2], $result->failedReferences);
    }

    #[Test]
    public function it_does_not_log_error_when_all_saves_succeed(): void
    {
        $definitions = [
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
        ];

        $this->client->shouldReceive('listAll')
            ->once()
            ->andReturn($definitions);

        $this->repository->shouldReceive('saveMany')
            ->once()
            ->andReturn(SaveManyResult::success(1));

        // Error should NOT be logged
        $this->logger->shouldNotReceive('error');

        $this->useCase->execute();
    }
}
