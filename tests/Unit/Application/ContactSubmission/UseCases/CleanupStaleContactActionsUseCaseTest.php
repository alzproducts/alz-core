<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\RecoverableActionDTO;
use App\Application\ContactSubmission\UseCases\CleanupStaleContactActionsUseCase;
use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(CleanupStaleContactActionsUseCase::class)]
final class CleanupStaleContactActionsUseCaseTest extends TestCase
{
    private const string ACTION_ID = '0b9d3f2e-6a41-4c55-9d7e-1f2a3b4c5d6e';

    private const string SUBMISSION_ID = '5e6f7a8b-9c0d-4e1f-8a2b-3c4d5e6f7a8b';

    private const string OTHER_ACTION_ID = '7c8d9e0f-1a2b-4c3d-9e4f-5a6b7c8d9e0f';

    private ContactSubmissionActionRepositoryInterface&MockInterface $repository;

    private ContactFormDispatcherInterface&MockInterface $contactFormDispatcher;

    private ConversionDispatcherInterface&MockInterface $conversionDispatcher;

    private LoggerInterface&MockInterface $logger;

    private CleanupStaleContactActionsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->contactFormDispatcher = Mockery::mock(ContactFormDispatcherInterface::class);
        $this->conversionDispatcher = Mockery::mock(ConversionDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new CleanupStaleContactActionsUseCase(
            $this->repository,
            $this->contactFormDispatcher,
            $this->conversionDispatcher,
            $this->logger,
        );
    }

    #[Test]
    public function it_queries_leases_older_than_six_minutes_and_pending_rows_overdue_by_ten(): void
    {
        $before = new DateTimeImmutable();
        $leaseCutoff = null;
        $overdueCutoff = null;

        $this->repository->expects('findExpiredLeases')
            ->withArgs(static function (DateTimeImmutable $cutoff) use (&$leaseCutoff): bool {
                $leaseCutoff = $cutoff;

                return true;
            })
            ->andReturn([]);
        $this->repository->expects('findOverduePending')
            ->withArgs(static function (DateTimeImmutable $cutoff) use (&$overdueCutoff): bool {
                $overdueCutoff = $cutoff;

                return true;
            })
            ->andReturn([]);

        $this->useCase->execute();
        $after = new DateTimeImmutable();

        self::assertInstanceOf(DateTimeImmutable::class, $leaseCutoff);
        self::assertInstanceOf(DateTimeImmutable::class, $overdueCutoff);
        self::assertGreaterThanOrEqual($before->modify('-360 seconds'), $leaseCutoff);
        self::assertLessThanOrEqual($after->modify('-360 seconds'), $leaseCutoff);
        self::assertGreaterThanOrEqual($before->modify('-600 seconds'), $overdueCutoff);
        self::assertLessThanOrEqual($after->modify('-600 seconds'), $overdueCutoff);
    }

    #[Test]
    public function a_released_helpscout_lease_is_dispatched_with_the_same_cutoff_and_counted(): void
    {
        $action = self::action(ActionType::HelpScout);
        $this->repository->expects('findExpiredLeases')->andReturn([$action]);
        $this->repository->expects('releaseExpiredLease')
            ->withArgs(static fn(string $actionId, DateTimeImmutable $claimedBefore): bool => $actionId === self::ACTION_ID)
            ->andReturn(true);
        $this->repository->expects('findOverduePending')->andReturn([]);
        $this->contactFormDispatcher->expects('dispatchContactSubmissionProcessing')
            ->with(self::SUBMISSION_ID, self::ACTION_ID);

        $this->logger->expects('warning')->with('Released expired contact action lease', self::context(ActionType::HelpScout, null));
        $this->logger->expects('info')->with('Completed stale contact action sweep', ['released_count' => 1, 'overdue_count' => 0]);

        $this->useCase->execute();
    }

    #[Test]
    public function a_lease_another_worker_already_moved_is_neither_dispatched_nor_counted(): void
    {
        $this->repository->expects('findExpiredLeases')->andReturn([self::action(ActionType::HelpScout)]);
        $this->repository->expects('releaseExpiredLease')->andReturn(false);
        $this->repository->expects('findOverduePending')->andReturn([]);
        $this->contactFormDispatcher->shouldNotReceive('dispatchContactSubmissionProcessing');

        $this->logger->shouldNotReceive('warning');
        $this->logger->expects('info')->with('Completed stale contact action sweep', ['released_count' => 0, 'overdue_count' => 0]);

        $this->useCase->execute();
    }

    #[Test]
    public function a_deferred_overdue_lead_dispatches_its_lead_conversion_and_is_counted(): void
    {
        $this->repository->expects('findExpiredLeases')->andReturn([]);
        $this->repository->expects('findOverduePending')->andReturn([self::action(ActionType::LeadReceived, AdPlatform::Bing)]);
        $this->repository->expects('deferOverduePending')
            ->withArgs(static fn(string $actionId, DateTimeImmutable $dueBefore): bool => $actionId === self::ACTION_ID)
            ->andReturn(true);
        $this->conversionDispatcher->expects('dispatchLeadConversion')
            ->withArgs(static fn(LeadConversionCommand $command): bool => $command->submissionId->value === self::SUBMISSION_ID
                && $command->actionId->value === self::ACTION_ID
                && $command->platform === AdPlatform::Bing);
        $this->contactFormDispatcher->shouldNotReceive('dispatchContactSubmissionProcessing');

        $this->logger->expects('warning')->with(
            'Re-dispatching overdue pending contact action',
            self::context(ActionType::LeadReceived, 'bing'),
        );
        $this->logger->expects('info')->with('Completed stale contact action sweep', ['released_count' => 0, 'overdue_count' => 1]);

        $this->useCase->execute();
    }

    #[Test]
    public function an_overdue_row_another_worker_already_deferred_is_neither_dispatched_nor_counted(): void
    {
        $this->repository->expects('findExpiredLeases')->andReturn([]);
        $this->repository->expects('findOverduePending')->andReturn([self::action(ActionType::LeadReceived, AdPlatform::Bing)]);
        $this->repository->expects('deferOverduePending')->andReturn(false);
        $this->conversionDispatcher->shouldNotReceive('dispatchLeadConversion');

        $this->logger->shouldNotReceive('warning');
        $this->logger->expects('info')->with('Completed stale contact action sweep', ['released_count' => 0, 'overdue_count' => 0]);

        $this->useCase->execute();
    }

    #[Test]
    public function a_quote_dispatches_a_quote_conversion_carrying_its_stored_details(): void
    {
        $value = Money::exclusive(1234.5678, precision: null);
        $convertedAt = new DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $this->repository->expects('findExpiredLeases')->andReturn([]);
        $this->repository->expects('findOverduePending')->andReturn([
            self::action(ActionType::QuoteIssued, AdPlatform::Google, $value, $convertedAt),
        ]);
        $this->repository->expects('deferOverduePending')->andReturn(true);
        $this->conversionDispatcher->expects('dispatchQuoteConversion')
            ->withArgs(static fn(QuoteConversionCommand $command): bool => $command->submissionId->value === self::SUBMISSION_ID
                && $command->actionId->value === self::ACTION_ID
                && $command->value === $value
                && $command->convertedAt === $convertedAt
                && $command->platform === AdPlatform::Google);

        $this->useCase->execute();
    }

    #[Test]
    public function a_lead_without_an_ad_platform_is_failed_instead_of_dispatched(): void
    {
        $this->repository->expects('findExpiredLeases')->andReturn([self::action(ActionType::LeadReceived)]);
        $this->repository->expects('releaseExpiredLease')->andReturn(true);
        $this->repository->expects('findOverduePending')->andReturn([]);
        $this->repository->expects('failUnclaimed')
            ->with(self::ACTION_ID, 'Conversion action has no ad platform')
            ->andReturn(true);
        $this->conversionDispatcher->shouldNotReceive('dispatchLeadConversion');

        $this->logger->expects('error')->with(
            'Cannot rebuild contact action command from its row — failing it',
            [...self::context(ActionType::LeadReceived, null), 'reason' => 'Conversion action has no ad platform'],
        );

        $this->useCase->execute();
    }

    /**
     * @return iterable<string, array{?AdPlatform, ?Money, ?DateTimeImmutable}>
     */
    public static function incompleteQuotes(): iterable
    {
        $value = Money::exclusive(10.0);
        $convertedAt = new DateTimeImmutable('2026-10-01T12:00:00+00:00');

        yield 'no platform' => [null, $value, $convertedAt];
        yield 'no value' => [AdPlatform::Google, null, $convertedAt];
        yield 'no converted-at' => [AdPlatform::Google, $value, null];
    }

    #[Test]
    #[DataProvider('incompleteQuotes')]
    public function a_quote_missing_any_stored_detail_is_failed_instead_of_dispatched(
        ?AdPlatform $platform,
        ?Money $value,
        ?DateTimeImmutable $convertedAt,
    ): void {
        $this->repository->expects('findExpiredLeases')->andReturn([]);
        $this->repository->expects('findOverduePending')->andReturn([
            self::action(ActionType::QuoteIssued, $platform, $value, $convertedAt),
        ]);
        $this->repository->expects('deferOverduePending')->andReturn(true);
        $this->repository->expects('failUnclaimed')
            ->with(self::ACTION_ID, 'Quote action has no stored ad platform, value or converted-at')
            ->andReturn(true);
        $this->conversionDispatcher->shouldNotReceive('dispatchQuoteConversion');

        $this->useCase->execute();
    }

    #[Test]
    public function a_failed_write_is_logged_and_the_sweep_continues_with_the_next_row(): void
    {
        $other = new RecoverableActionDTO(self::OTHER_ACTION_ID, self::SUBMISSION_ID, ActionType::HelpScout, null, null, null);
        $this->repository->expects('findExpiredLeases')->andReturn([self::action(ActionType::HelpScout), $other]);
        $this->repository->expects('releaseExpiredLease')
            ->withArgs(static fn(string $actionId): bool => $actionId === self::ACTION_ID)
            ->andThrow(new DatabaseOperationFailedException('update', 'connection reset'));
        $this->repository->expects('releaseExpiredLease')
            ->withArgs(static fn(string $actionId): bool => $actionId === self::OTHER_ACTION_ID)
            ->andReturn(true);
        $this->repository->expects('findOverduePending')->andReturn([self::action(ActionType::HelpScout)]);
        $this->repository->expects('deferOverduePending')
            ->andThrow(new DatabaseOperationFailedException('update', 'connection reset'));
        $this->contactFormDispatcher->expects('dispatchContactSubmissionProcessing')
            ->with(self::SUBMISSION_ID, self::OTHER_ACTION_ID);

        $this->logger->expects('error')->twice()->with(
            'Failed to recover stale contact action',
            [...self::context(ActionType::HelpScout, null), 'error' => 'Database operation failed'],
        );
        $this->logger->expects('info')->with('Completed stale contact action sweep', ['released_count' => 1, 'overdue_count' => 0]);

        $this->useCase->execute();
    }

    private static function action(
        ActionType $type,
        ?AdPlatform $platform = null,
        ?Money $value = null,
        ?DateTimeImmutable $convertedAt = null,
    ): RecoverableActionDTO {
        return new RecoverableActionDTO(self::ACTION_ID, self::SUBMISSION_ID, $type, $platform, $value, $convertedAt);
    }

    /**
     * @return array<string, string|null>
     */
    private static function context(ActionType $type, ?string $platform): array
    {
        return [
            'action_id' => self::ACTION_ID,
            'submission_id' => self::SUBMISSION_ID,
            'action_type' => $type->value,
            'ad_platform' => $platform,
        ];
    }
}
