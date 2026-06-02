<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\UseCases\MarkNoQuoteExpectedUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\PotentialConversionSource;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\ContactSubmission\Exceptions\OperationNotSupportedForSourceException;
use App\Domain\ContactSubmission\ValueObjects\PotentialConversionStage;
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

    private ContactSubmissionDashboardQueryRepositoryInterface&MockInterface $dashboardQueryRepository;

    private PotentialConversionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private MarkNoQuoteExpectedUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardQueryRepository = Mockery::mock(ContactSubmissionDashboardQueryRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(PotentialConversionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new MarkNoQuoteExpectedUseCase(
            dashboardQueryRepository: $this->dashboardQueryRepository,
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
    public function marks_no_quote_expected_when_form_lead_completed_and_no_quote_action(): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(PotentialConversionSource::Form, ActionStatus::Completed, null));

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
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(PotentialConversionSource::Form, ActionStatus::Completed, null));

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
                ['source_id' => self::SUBMISSION_ID],
            );

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    #[Test]
    public function throws_record_not_found_when_row_missing(): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andThrow(new RecordNotFoundException('PotentialConversion', self::SUBMISSION_ID));

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        $this->expectException(RecordNotFoundException::class);

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    #[Test]
    public function throws_operation_not_supported_when_row_is_a_call(): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(PotentialConversionSource::Call, ActionStatus::Completed, null));

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID));
            self::fail('Expected OperationNotSupportedForSourceException');
        } catch (OperationNotSupportedForSourceException $e) {
            self::assertSame(self::SUBMISSION_ID, $e->sourceId);
            self::assertSame('call', $e->source);
            self::assertSame('markNoQuoteExpected', $e->operation);
        }
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
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(PotentialConversionSource::Form, $leadStatus, null));

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
    public function throws_invalid_action_stage_when_quote_status_present_in_any_status(ActionStatus $status): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findStageById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(PotentialConversionSource::Form, ActionStatus::Completed, $status));

        $this->annotationRepository->shouldNotReceive('markNoQuoteExpected');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID));
            self::fail('Expected InvalidActionStageException');
        } catch (InvalidActionStageException $e) {
            self::assertSame(ActionType::QuoteIssued, $e->action);
            self::assertSame($status, $e->currentStatus);
        }
    }

    private static function stubRow(
        PotentialConversionSource $source,
        ?ActionStatus $leadStatus,
        ?ActionStatus $quoteStatus,
    ): PotentialConversionStage {
        return new PotentialConversionStage(
            source: $source,
            leadStatus: $leadStatus,
            quoteStatus: $quoteStatus,
        );
    }
}
