<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * ProcessContactSubmissionUseCase Unit Tests.
 *
 * Tests the core processing workflow:
 * - Idempotency check (skip if already completed)
 * - HelpScout conversation creation
 * - Email validation note addition (non-blocking)
 */
#[CoversClass(ProcessContactSubmissionUseCase::class)]
final class ProcessContactSubmissionUseCaseTest extends TestCase
{
    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ConversationWriteClientInterface&MockInterface $helpScoutClient;

    private ContactSubmissionToConversationCommandTransformer&MockInterface $transformer;

    private EmailValidationServiceInterface&MockInterface $emailValidator;

    private LoggerInterface&MockInterface $logger;

    private ProcessContactSubmissionUseCase $useCase;

    private const string SUBMISSION_ID = 'submission-uuid-123';

    private const string ACTION_ID = 'action-uuid-456';

    private const int CONVERSATION_ID = 99999;

    private const int SYSTEM_USER_ID = 12345;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ContactFormProcessedEvent::class]);

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->helpScoutClient = Mockery::mock(ConversationWriteClientInterface::class);
        $this->transformer = Mockery::mock(ContactSubmissionToConversationCommandTransformer::class);
        $this->emailValidator = Mockery::mock(EmailValidationServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new ProcessContactSubmissionUseCase(
            submissionRepository: $this->submissionRepository,
            actionRepository: $this->actionRepository,
            helpScoutClient: $this->helpScoutClient,
            transformer: $this->transformer,
            emailValidator: $this->emailValidator,
            logger: $this->logger,
            helpScoutSystemUserId: new HelpScoutSystemUserId(IntId::from(self::SYSTEM_USER_ID)),
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency Tests - CRITICAL BUSINESS LOGIC
    |--------------------------------------------------------------------------
    | These tests verify the idempotency check prevents duplicate HelpScout tickets
    | when jobs are retried. Already-completed actions should return null immediately.
    */

    #[Test]
    public function execute_returns_null_when_action_already_completed(): void
    {
        $this->actionRepository->expects('getStatus')
            ->with(self::ACTION_ID)
            ->andReturn(ActionStatus::Completed);

        // Should NOT call any other methods
        $this->actionRepository->shouldNotReceive('incrementAttempts');
        $this->actionRepository->shouldNotReceive('markProcessing');
        $this->submissionRepository->shouldNotReceive('findById');
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertNull($result);
    }

    #[Test]
    public function execute_proceeds_when_action_status_is_pending(): void
    {
        $this->setupSuccessfulProcessing(ActionStatus::Pending);

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(self::CONVERSATION_ID, $result);
    }

    #[Test]
    public function execute_proceeds_when_action_status_is_processing(): void
    {
        $this->setupSuccessfulProcessing(ActionStatus::Processing);

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(self::CONVERSATION_ID, $result);
    }

    #[Test]
    public function execute_proceeds_when_action_status_is_failed(): void
    {
        // Failed actions can be retried
        $this->setupSuccessfulProcessing(ActionStatus::Failed);

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(self::CONVERSATION_ID, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_increments_attempts_and_marks_processing_before_work(): void
    {
        $callOrder = [];

        $this->actionRepository->expects('getStatus')
            ->andReturn(ActionStatus::Pending);

        $this->actionRepository->expects('incrementAttempts')
            ->with(self::ACTION_ID)
            ->andReturnUsing(static function () use (&$callOrder): void {
                $callOrder[] = 'incrementAttempts';
            });

        $this->actionRepository->expects('markProcessing')
            ->with(self::ACTION_ID)
            ->andReturnUsing(static function () use (&$callOrder): void {
                $callOrder[] = 'markProcessing';
            });

        $this->submissionRepository->expects('findById')
            ->andReturnUsing(function () use (&$callOrder): ContactSubmission {
                $callOrder[] = 'findById';

                return $this->createSubmission();
            });

        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());

        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);

        $this->emailValidator->expects('isValid')
            ->andReturn(true);

        $this->actionRepository->expects('markCompleted')
            ->with(self::ACTION_ID, (string) self::CONVERSATION_ID);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(['incrementAttempts', 'markProcessing', 'findById'], $callOrder);
    }

    #[Test]
    public function execute_returns_conversation_id_on_success(): void
    {
        $this->setupSuccessfulProcessing();

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(self::CONVERSATION_ID, $result);
    }

    #[Test]
    public function execute_fires_success_event_with_submission_details(): void
    {
        $this->setupSuccessfulProcessing();

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertDispatched(
            ContactFormProcessedEvent::class,
            static fn(ContactFormProcessedEvent $event): bool => $event->submissionId === self::SUBMISSION_ID
                    && $event->conversationId->value === self::CONVERSATION_ID
                    && $event->customerName === 'Test User'
                    && $event->customerEmail === 'test@example.com',
        );
    }

    #[Test]
    public function execute_does_not_fire_event_when_already_completed(): void
    {
        $this->actionRepository->expects('getStatus')
            ->with(self::ACTION_ID)
            ->andReturn(ActionStatus::Completed);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertNotDispatched(ContactFormProcessedEvent::class);
    }

    #[Test]
    public function execute_marks_action_completed_with_conversation_id(): void
    {
        $submission = $this->createSubmission();

        $this->actionRepository->expects('getStatus')
            ->andReturn(ActionStatus::Pending);
        $this->actionRepository->allows('incrementAttempts');
        $this->actionRepository->allows('markProcessing');
        $this->submissionRepository->expects('findById')
            ->andReturn($submission);
        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);
        $this->emailValidator->allows('isValid')
            ->andReturn(true);

        $this->actionRepository->expects('markCompleted')
            ->with(self::ACTION_ID, (string) self::CONVERSATION_ID);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Validation Note Tests
    |--------------------------------------------------------------------------
    | Email validation is non-blocking - failures log but don't fail the submission.
    */

    #[Test]
    public function execute_adds_note_when_email_is_invalid(): void
    {
        $submission = $this->createSubmission(email: 'invalid@fake-domain-xyz.com');

        $this->actionRepository->expects('getStatus')
            ->andReturn(ActionStatus::Pending);
        $this->actionRepository->allows('incrementAttempts');
        $this->actionRepository->expects('markProcessing');
        $this->submissionRepository->expects('findById')
            ->andReturn($submission);
        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);

        $this->emailValidator->expects('isValid')
            ->with('invalid@fake-domain-xyz.com')
            ->andReturn(false);

        $this->helpScoutClient->expects('addNoteToConversation')
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === self::CONVERSATION_ID),
                Mockery::on(static fn(string $text): bool => \str_contains($text, 'invalid@fake-domain-xyz.com')
                    && \str_contains($text, 'Email validation warning')),
                Mockery::on(static fn(IntId $id): bool => $id->value === self::SYSTEM_USER_ID),
            );

        $this->logger->expects('info')
            ->with(
                'Added note to HelpScout conversation',
                Mockery::on(static fn(array $ctx): bool => $ctx['conversation_id'] === self::CONVERSATION_ID),
            );

        $this->actionRepository->expects('markCompleted');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    #[Test]
    public function execute_does_not_add_note_when_email_is_valid(): void
    {
        $submission = $this->createSubmission();

        $this->actionRepository->expects('getStatus')
            ->andReturn(ActionStatus::Pending);
        $this->actionRepository->allows('incrementAttempts');
        $this->actionRepository->allows('markProcessing');
        $this->submissionRepository->expects('findById')
            ->andReturn($submission);
        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);
        $this->actionRepository->allows('markCompleted');

        $this->emailValidator->expects('isValid')
            ->andReturn(true);

        $this->helpScoutClient->shouldNotReceive('addNoteToConversation');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    #[Test]
    public function execute_succeeds_when_adding_note_fails(): void
    {
        $submission = $this->createSubmission();

        $this->actionRepository->expects('getStatus')
            ->andReturn(ActionStatus::Pending);
        $this->actionRepository->allows('incrementAttempts');
        $this->actionRepository->expects('markProcessing');
        $this->submissionRepository->expects('findById')
            ->andReturn($submission);
        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);

        $this->emailValidator->expects('isValid')
            ->andReturn(false);

        // Note addition fails
        $this->helpScoutClient->expects('addNoteToConversation')
            ->andThrow(new ExternalServiceUnavailableException('HelpScout', 60));

        // Error is logged but processing continues
        $this->logger->expects('error')
            ->with(
                'Failed to add note to HelpScout conversation',
                Mockery::on(static fn(array $ctx): bool => $ctx['conversation_id'] === self::CONVERSATION_ID
                    && isset($ctx['error'])),
            );

        // Action is still marked completed
        $this->actionRepository->expects('markCompleted')
            ->with(self::ACTION_ID, (string) self::CONVERSATION_ID);

        $result = $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(self::CONVERSATION_ID, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function setupSuccessfulProcessing(ActionStatus $initialStatus = ActionStatus::Pending): void
    {
        $submission = $this->createSubmission();

        $this->actionRepository->expects('getStatus')
            ->andReturn($initialStatus);
        $this->actionRepository->allows('incrementAttempts');
        $this->actionRepository->allows('markProcessing');
        $this->submissionRepository->expects('findById')
            ->andReturn($submission);
        $this->transformer->expects('transform')
            ->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturn(self::CONVERSATION_ID);
        $this->emailValidator->allows('isValid')
            ->andReturn(true);
        $this->actionRepository->allows('markCompleted');
    }

    private function createSubmission(string $email = 'test@example.com'): ContactSubmission
    {
        $form = new ContactFormData(
            name: 'Test User',
            email: $email,
            reason: ContactReason::Other,
            message: 'Test message',
        );

        $context = new SubmissionContext(
            clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
            ipAddress: '127.0.0.1',
        );

        return new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: $context,
        );
    }

    private function createCommand(): CreateCustomerConversationCommand
    {
        return new CreateCustomerConversationCommand(
            email: 'test@example.com',
            name: 'Test User',
            subject: 'Test Subject',
            body: 'Test body',
            mailbox: Mailbox::Support,
            type: ConversationType::Email,
            status: ConversationStatus::Active,
        );
    }
}
