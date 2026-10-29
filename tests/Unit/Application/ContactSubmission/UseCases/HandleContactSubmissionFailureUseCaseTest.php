<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\UseCases\HandleContactSubmissionFailureUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(HandleContactSubmissionFailureUseCase::class)]
final class HandleContactSubmissionFailureUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = 'submission-uuid-123';

    private const string ACTION_ID = 'action-uuid-456';

    private const string TOKEN = 'claim-token-789';

    private const string MESSAGE = 'API request validation failed';

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private EmailValidationServiceInterface&MockInterface $emailValidator;

    private LoggerInterface&MockInterface $logger;

    private HandleContactSubmissionFailureUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ContactFormProcessingFailedEvent::class]);

        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->emailValidator = Mockery::mock(EmailValidationServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new HandleContactSubmissionFailureUseCase(
            actionRepository: $this->actionRepository,
            submissionRepository: $this->submissionRepository,
            emailValidator: $this->emailValidator,
            logger: $this->logger,
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    #[Test]
    public function it_fails_the_claim_and_fires_the_failure_event(): void
    {
        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::TOKEN, self::MESSAGE)
            ->andReturn(true);
        $this->submissionRepository->expects('findById')->with(self::SUBMISSION_ID)->andReturn($this->submission());
        $this->emailValidator->expects('isValid')->with('test@example.com')->andReturn(false);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, self::MESSAGE);

        Event::assertDispatched(
            ContactFormProcessingFailedEvent::class,
            static fn(ContactFormProcessingFailedEvent $event): bool => $event->submissionId === self::SUBMISSION_ID
                && $event->exceptionMessage === self::MESSAGE
                && $event->emailValid === false,
        );
    }

    #[Test]
    public function a_lost_claim_fires_no_event(): void
    {
        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::TOKEN, self::MESSAGE)
            ->andReturn(false);
        $this->submissionRepository->shouldNotReceive('findById');
        $this->emailValidator->shouldNotReceive('isValid');

        $this->logger->expects('warning')
            ->with(
                'Contact submission action claim lost',
                ['submission_id' => self::SUBMISSION_ID, 'action_id' => self::ACTION_ID],
            );

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, self::MESSAGE);

        Event::assertNotDispatched(ContactFormProcessingFailedEvent::class);
    }

    #[Test]
    public function it_fires_the_event_with_unknown_email_validity_when_the_lookup_fails(): void
    {
        $this->actionRepository->expects('failClaim')->andReturn(true);
        $this->submissionRepository->expects('findById')
            ->andThrow(new ExternalServiceUnavailableException('Database'));

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, self::MESSAGE);

        Event::assertDispatched(
            ContactFormProcessingFailedEvent::class,
            static fn(ContactFormProcessingFailedEvent $event): bool => $event->emailValid === null,
        );
    }

    private function submission(): ContactSubmission
    {
        return new ContactSubmission(
            form: new ContactFormData(
                name: 'Test User',
                email: 'test@example.com',
                reason: ContactReason::Other,
                message: 'Test message',
            ),
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
                ipAddress: '127.0.0.1',
            ),
        );
    }
}
