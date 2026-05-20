<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\UseCases\MarkNoQuoteExpectedUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\Guid;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(MarkNoQuoteExpectedUseCase::class)]
final class MarkNoQuoteExpectedUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '019d9358-01fe-72c9-b123-5f452270d3c1';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ContactSubmissionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private MarkNoQuoteExpectedUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(ContactSubmissionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new MarkNoQuoteExpectedUseCase(
            submissionRepository: $this->submissionRepository,
            actionRepository: $this->actionRepository,
            annotationRepository: $this->annotationRepository,
            logger: $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function marks_no_quote_expected_when_lead_completed_and_no_quote_action(): void
    {
        $this->submissionRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(ActionStatus::Completed);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued)
            ->andReturnNull();

        $this->annotationRepository
            ->shouldReceive('markNoQuoteExpected')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturnTrue();

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    #[Test]
    public function logs_warning_when_atomic_guard_blocks_write(): void
    {
        $this->submissionRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(ActionStatus::Completed);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued)
            ->andReturnNull();

        $this->annotationRepository
            ->shouldReceive('markNoQuoteExpected')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturnFalse();

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->with(
                'markNoQuoteExpected atomic guard fired — concurrent quote action blocked the write',
                ['submission_id' => self::SUBMISSION_ID],
            );

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    #[Test]
    public function throws_record_not_found_when_submission_missing(): void
    {
        $this->submissionRepository
            ->shouldReceive('findById')
            ->once()
            ->andThrow(new RecordNotFoundException('ContactSubmission', self::SUBMISSION_ID));

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        $this->expectException(RecordNotFoundException::class);

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    /**
     * @return iterable<string, array{0: ?ActionStatus}>
     */
    public static function nonCompletedLeadStatuses(): iterable
    {
        yield 'no lead row' => [null];
        yield 'pending lead' => [ActionStatus::Pending];
        yield 'processing lead' => [ActionStatus::Processing];
        yield 'failed lead' => [ActionStatus::Failed];
    }

    #[Test]
    #[DataProvider('nonCompletedLeadStatuses')]
    public function throws_invalid_action_stage_when_lead_not_completed(?ActionStatus $leadStatus): void
    {
        $this->submissionRepository->shouldReceive('findById')->once();

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn($leadStatus);

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID));
            self::fail('Expected InvalidActionStageException');
        } catch (InvalidActionStageException $e) {
            self::assertSame(ActionType::LeadReceived, $e->action);
            self::assertSame($leadStatus, $e->currentStatus);
        }
    }

    /**
     * @return iterable<string, array{0: ActionStatus}>
     */
    public static function quoteActionStatuses(): iterable
    {
        yield 'pending quote' => [ActionStatus::Pending];
        yield 'processing quote' => [ActionStatus::Processing];
        yield 'completed quote' => [ActionStatus::Completed];
        yield 'failed quote' => [ActionStatus::Failed];
    }

    #[Test]
    #[DataProvider('quoteActionStatuses')]
    public function throws_invalid_action_stage_when_quote_action_exists_in_any_status(ActionStatus $status): void
    {
        $this->submissionRepository->shouldReceive('findById')->once();

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(ActionStatus::Completed);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued)
            ->andReturn($status);

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID));
            self::fail('Expected InvalidActionStageException');
        } catch (InvalidActionStageException $e) {
            self::assertSame(ActionType::QuoteIssued, $e->action);
            self::assertSame($status, $e->currentStatus);
        }
    }
}
