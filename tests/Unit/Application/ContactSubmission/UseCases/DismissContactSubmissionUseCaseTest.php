<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\UseCases\DismissContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(DismissContactSubmissionUseCase::class)]
final class DismissContactSubmissionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '019d9358-01fe-72c9-b123-5f452270d3c1';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ContactSubmissionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private DismissContactSubmissionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(ContactSubmissionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new DismissContactSubmissionUseCase(
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
    public function dismisses_when_no_lead_action_exists(): void
    {
        $this->submissionRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID);

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturnNull();

        $this->annotationRepository
            ->shouldReceive('markDismissed')
            ->once()
            ->with(self::SUBMISSION_ID);

        $this->useCase->execute(self::SUBMISSION_ID);
    }

    #[Test]
    public function throws_record_not_found_when_submission_missing(): void
    {
        $this->submissionRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andThrow(new RecordNotFoundException('ContactSubmission', self::SUBMISSION_ID));

        $this->actionRepository->shouldNotReceive('findActionStatus');
        $this->annotationRepository->shouldNotReceive('markDismissed');

        $this->expectException(RecordNotFoundException::class);

        $this->useCase->execute(self::SUBMISSION_ID);
    }

    /**
     * @return iterable<string, array{0: ActionStatus}>
     */
    public static function leadActionStatuses(): iterable
    {
        yield 'pending lead' => [ActionStatus::Pending];
        yield 'processing lead' => [ActionStatus::Processing];
        yield 'completed lead' => [ActionStatus::Completed];
        yield 'failed lead' => [ActionStatus::Failed];
    }

    #[Test]
    #[DataProvider('leadActionStatuses')]
    public function throws_invalid_action_stage_when_lead_action_exists_in_any_status(ActionStatus $status): void
    {
        $this->submissionRepository->shouldReceive('findById')->once();

        $this->actionRepository
            ->shouldReceive('findActionStatus')
            ->once()
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn($status);

        $this->annotationRepository->shouldNotReceive('markDismissed');

        try {
            $this->useCase->execute(self::SUBMISSION_ID);
            self::fail('Expected InvalidActionStageException');
        } catch (InvalidActionStageException $e) {
            self::assertSame(self::SUBMISSION_ID, $e->submissionId);
            self::assertSame(ActionType::LeadReceived, $e->action);
            self::assertSame($status, $e->currentStatus);
        }
    }
}
