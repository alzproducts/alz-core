<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\ContactSubmissionListItemDTO;
use App\Application\ContactSubmission\Enums\PotentialConversionSource;
use App\Application\ContactSubmission\UseCases\DismissContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
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

    private ContactSubmissionDashboardQueryRepositoryInterface&MockInterface $dashboardQueryRepository;

    private PotentialConversionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private LoggerInterface&MockInterface $logger;

    private DismissContactSubmissionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboardQueryRepository = Mockery::mock(ContactSubmissionDashboardQueryRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(PotentialConversionAnnotationRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new DismissContactSubmissionUseCase(
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
    public function dismisses_when_no_lead_status_present(): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(leadStatus: null));

        $this->annotationRepository
            ->shouldReceive('markDismissed')
            ->once()
            ->with(self::SUBMISSION_ID);

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
    }

    #[Test]
    public function throws_record_not_found_when_row_missing(): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andThrow(new RecordNotFoundException('PotentialConversion', self::SUBMISSION_ID));

        $this->annotationRepository->shouldNotReceive('markDismissed');

        $this->expectException(RecordNotFoundException::class);

        $this->useCase->execute(new Guid(self::SUBMISSION_ID));
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
    public function throws_invalid_action_stage_when_lead_status_present_in_any_status(ActionStatus $status): void
    {
        $this->dashboardQueryRepository
            ->shouldReceive('findById')
            ->once()
            ->with(self::SUBMISSION_ID)
            ->andReturn(self::stubRow(leadStatus: $status));

        $this->annotationRepository->shouldNotReceive('markDismissed');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID));
            self::fail('Expected InvalidActionStageException');
        } catch (InvalidActionStageException $e) {
            self::assertSame(self::SUBMISSION_ID, $e->submissionId);
            self::assertSame(ActionType::LeadReceived, $e->action);
            self::assertSame($status, $e->currentStatus);
        }
    }

    private static function stubRow(?ActionStatus $leadStatus): ContactSubmissionListItemDTO
    {
        return new ContactSubmissionListItemDTO(
            id: Guid::fromTrusted(self::SUBMISSION_ID),
            source: PotentialConversionSource::Form,
            name: null,
            email: null,
            reason: null,
            customerType: null,
            orderNumber: null,
            quantity: null,
            product: null,
            shopwiredCustomerId: null,
            gclid: null,
            msclkid: null,
            fbclid: null,
            utmSource: null,
            utmMedium: null,
            utmCampaign: null,
            pageUrl: null,
            createdAt: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
            helpscoutExternalId: null,
            leadStatus: $leadStatus,
            quoteStatus: null,
            isPotentialQuote: null,
            notes: null,
            quotedAt: null,
            callerPhoneNumber: null,
        );
    }
}
