<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\ContactSubmission\Services\EmailValidationNoteService;
use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Application\ContactSubmission\UseCases\HandleContactSubmissionFailureUseCase;
use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\ContactSubmission\ValueObjects\ActionAttempts;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

#[CoversClass(ProcessContactSubmissionUseCase::class)]
#[CoversClass(EmailValidationNoteService::class)]
final class ProcessContactSubmissionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = 'submission-uuid-123';

    private const string ACTION_ID = 'action-uuid-456';

    private const string TOKEN = 'claim-token-789';

    private const int CONVERSATION_ID = 99999;

    private const int SYSTEM_USER_ID = 12345;

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ConversationWriteClientInterface&MockInterface $helpScoutClient;

    private ContactSubmissionToConversationCommandTransformer&MockInterface $transformer;

    private EmailValidationServiceInterface&MockInterface $emailValidator;

    private LoggerInterface&MockInterface $logger;

    private HandleContactSubmissionFailureUseCase&MockInterface $failureHandler;

    private ProcessContactSubmissionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ContactFormProcessedEvent::class]);

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->helpScoutClient = Mockery::mock(ConversationWriteClientInterface::class);
        $this->transformer = Mockery::mock(ContactSubmissionToConversationCommandTransformer::class);
        $this->emailValidator = Mockery::mock(EmailValidationServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->failureHandler = Mockery::mock(HandleContactSubmissionFailureUseCase::class);

        $this->useCase = new ProcessContactSubmissionUseCase(
            submissionRepository: $this->submissionRepository,
            actionRepository: $this->actionRepository,
            helpScoutClient: $this->helpScoutClient,
            transformer: $this->transformer,
            emailValidationNote: new EmailValidationNoteService(
                helpScoutClient: $this->helpScoutClient,
                emailValidator: $this->emailValidator,
                helpScoutSystemUserId: new HelpScoutSystemUserId(IntId::from(self::SYSTEM_USER_ID)),
                logger: $this->logger,
            ),
            logger: $this->logger,
            eventDispatcher: \app(Dispatcher::class),
            failureHandler: $this->failureHandler,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Routing and claim
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_throws_type_mismatch_for_a_lead_action_before_any_write(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::LeadReceived);
        $this->actionRepository->shouldNotReceive('claim');
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
            self::fail('Expected ActionTypeMismatchException');
        } catch (ActionTypeMismatchException $e) {
            self::assertSame(self::ACTION_ID, $e->actionId);
            self::assertSame(ActionType::HelpScout, $e->expected);
            self::assertSame(ActionType::LeadReceived, $e->actual);
        }
    }

    #[Test]
    public function execute_returns_without_claiming_when_the_action_does_not_exist(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturnNull();
        $this->actionRepository->shouldNotReceive('claim');
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertNotDispatched(ContactFormProcessedEvent::class);
    }

    #[Test]
    public function only_the_run_that_claims_the_action_creates_a_conversation(): void
    {
        $this->actionRepository->allows('findActionType')->andReturn(ActionType::HelpScout);
        $this->actionRepository->expects('claim')->twice()->with(self::ACTION_ID)->andReturn($this->claim(), null);
        $this->expectSubmission();
        $this->expectCreate();
        $this->emailValidator->allows('isValid')->andReturn(true);
        $this->actionRepository->expects('completeClaim')->once()->andReturn(true);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertDispatchedTimes(ContactFormProcessedEvent::class, 1);
    }

    #[Test]
    public function execute_fails_an_action_past_the_attempt_limit_without_an_external_call(): void
    {
        $this->expectClaim($this->claim(attempts: ActionAttempts::LIMIT + 1));
        $this->submissionRepository->shouldNotReceive('findById');
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');
        $this->helpScoutClient->shouldNotReceive('addNoteToConversation');
        $this->actionRepository->shouldNotReceive('completeClaim');

        $this->failureHandler->expects('execute')
            ->with(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, 'Attempt limit reached')
            ->andReturnTrue();

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertNotDispatched(ContactFormProcessedEvent::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful create
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_completes_the_claim_with_the_conversation_id_before_the_event_and_note(): void
    {
        $callOrder = [];
        $eventsAtComplete = null;
        $eventsAtNote = null;

        $this->expectClaim($this->claim());
        $this->expectSubmission(email: 'invalid@fake-domain-xyz.com');
        $this->transformer->expects('transform')->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')
            ->andReturnUsing(static function () use (&$callOrder): int {
                $callOrder[] = 'create';

                return self::CONVERSATION_ID;
            });
        $this->actionRepository->expects('completeClaim')
            ->with(self::ACTION_ID, self::TOKEN, (string) self::CONVERSATION_ID)
            ->andReturnUsing(static function () use (&$callOrder, &$eventsAtComplete): bool {
                $callOrder[] = 'completeClaim';
                $eventsAtComplete = Event::dispatched(ContactFormProcessedEvent::class)->count();

                return true;
            });
        $this->emailValidator->expects('isValid')->with('invalid@fake-domain-xyz.com')->andReturn(false);
        $this->helpScoutClient->expects('addNoteToConversation')
            ->with(
                Mockery::on(static fn(IntId $id): bool => $id->value === self::CONVERSATION_ID),
                Mockery::on(static fn(string $text): bool => \str_contains($text, 'invalid@fake-domain-xyz.com')
                    && \str_contains($text, 'Email validation warning')),
                Mockery::on(static fn(IntId $id): bool => $id->value === self::SYSTEM_USER_ID),
            )
            ->andReturnUsing(static function () use (&$callOrder, &$eventsAtNote): void {
                $callOrder[] = 'note';
                $eventsAtNote = Event::dispatched(ContactFormProcessedEvent::class)->count();
            });

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        self::assertSame(['create', 'completeClaim', 'note'], $callOrder);
        self::assertSame(0, $eventsAtComplete);
        self::assertSame(1, $eventsAtNote);
    }

    #[Test]
    public function execute_fires_the_processed_event_with_submission_details(): void
    {
        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreate();
        $this->actionRepository->expects('completeClaim')->andReturn(true);
        $this->emailValidator->expects('isValid')->andReturn(true);
        $this->helpScoutClient->shouldNotReceive('addNoteToConversation');

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
    public function execute_completes_without_creating_when_the_action_already_has_an_external_id(): void
    {
        $this->expectClaim($this->claim(attempts: 2, externalId: '55555'));
        $this->expectSubmission();
        $this->transformer->shouldNotReceive('transform');
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');
        $this->actionRepository->expects('completeClaim')->with(self::ACTION_ID, self::TOKEN, '55555')->andReturn(true);
        $this->emailValidator->expects('isValid')->andReturn(true);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertDispatched(
            ContactFormProcessedEvent::class,
            static fn(ContactFormProcessedEvent $event): bool => $event->conversationId->value === 55555,
        );
    }

    #[Test]
    public function execute_keeps_the_action_completed_when_the_note_fails(): void
    {
        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreate();
        $this->actionRepository->expects('completeClaim')
            ->with(self::ACTION_ID, self::TOKEN, (string) self::CONVERSATION_ID)
            ->andReturn(true);
        $this->emailValidator->expects('isValid')->andReturn(false);
        $this->helpScoutClient->expects('addNoteToConversation')
            ->andThrow(new ExternalServiceUnavailableException('HelpScout', 60));
        $this->actionRepository->shouldNotReceive('returnToPending');
        $this->actionRepository->shouldNotReceive('failClaim');
        $this->failureHandler->shouldNotReceive('execute');

        $this->logger->expects('error')
            ->with(
                'Failed to add note to HelpScout conversation',
                Mockery::on(static fn(array $ctx): bool => $ctx['conversation_id'] === self::CONVERSATION_ID
                    && isset($ctx['error'])),
            );

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertDispatched(ContactFormProcessedEvent::class);
    }

    #[Test]
    public function a_lost_claim_on_complete_fires_no_event_and_adds_no_note(): void
    {
        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreate();
        $this->actionRepository->expects('completeClaim')->andReturn(false);
        $this->emailValidator->shouldNotReceive('isValid');
        $this->helpScoutClient->shouldNotReceive('addNoteToConversation');

        $this->logger->expects('warning')
            ->with(
                'Contact submission action claim lost',
                ['submission_id' => self::SUBMISSION_ID, 'action_id' => self::ACTION_ID],
            );

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertNotDispatched(ContactFormProcessedEvent::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_transient_failure_with_retry_after_returns_the_action_to_pending_after_that_delay(): void
    {
        $original = new ExternalServiceUnavailableException('HelpScout', retryAfter: 120);

        $this->expectClaim($this->claim(attempts: 2));
        $this->expectSubmission();
        $this->expectCreateThrows($original);
        $this->actionRepository->expects('returnToPending')->with(self::ACTION_ID, self::TOKEN, 120)->andReturn(true);
        $this->actionRepository->shouldNotReceive('completeClaim');
        $this->failureHandler->shouldNotReceive('execute');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('HelpScout', $e->serviceName);
            self::assertSame($original, $e->getPrevious());
        }
    }

    #[Test]
    public function a_transient_failure_without_retry_after_uses_the_attempt_backoff(): void
    {
        $this->expectClaim($this->claim(attempts: 2));
        $this->expectSubmission();
        $this->expectCreateThrows(new ExternalServiceUnavailableException('HelpScout'));
        $this->actionRepository->expects('returnToPending')->with(self::ACTION_ID, self::TOKEN, 300)->andReturn(true);

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(300, $e->retryAfter);
        }
    }

    #[Test]
    public function a_transient_failure_on_the_last_attempt_fails_the_action(): void
    {
        $this->expectClaim($this->claim(attempts: ActionAttempts::LIMIT));
        $this->expectSubmission();
        $this->expectCreateThrows(new ExternalServiceUnavailableException('HelpScout', retryAfter: 120));
        $this->actionRepository->shouldNotReceive('returnToPending');

        $this->failureHandler->expects('execute')
            ->with(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, 'External service unavailable')
            ->andReturnTrue();

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    #[Test]
    public function a_lost_claim_on_return_to_pending_does_not_rethrow(): void
    {
        $this->expectClaim($this->claim(attempts: 2));
        $this->expectSubmission();
        $this->expectCreateThrows(new ExternalServiceUnavailableException('HelpScout', retryAfter: 120));
        $this->actionRepository->expects('returnToPending')->andReturn(false);

        $this->logger->expects('warning')
            ->with(
                'Contact submission action claim lost',
                ['submission_id' => self::SUBMISSION_ID, 'action_id' => self::ACTION_ID],
            );

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent and unexpected failures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_permanent_api_failure_fails_the_action_and_rethrows(): void
    {
        $exception = new InvalidApiRequestException('HelpScout', 'bad request');

        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreateThrows($exception);
        $this->actionRepository->shouldNotReceive('returnToPending');
        $this->actionRepository->shouldNotReceive('completeClaim');

        $this->failureHandler->expects('execute')
            ->with(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, $exception->getMessage())
            ->andReturnTrue();

        $this->expectExceptionObject($exception);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    #[Test]
    public function a_permanent_api_failure_with_a_lost_claim_does_not_rethrow(): void
    {
        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreateThrows(new InvalidApiRequestException('HelpScout', 'bad request'));
        $this->actionRepository->shouldNotReceive('completeClaim');

        $this->failureHandler->expects('execute')->andReturnFalse();

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);

        Event::assertNotDispatched(ContactFormProcessedEvent::class);
    }

    #[Test]
    public function a_data_exception_fails_the_action_and_rethrows(): void
    {
        $exception = new InsufficientDataException('ContactSubmission', 'email or phone');

        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->transformer->expects('transform')->andThrow($exception);
        $this->helpScoutClient->shouldNotReceive('createConversationFromCustomer');

        $this->failureHandler->expects('execute')
            ->with(self::SUBMISSION_ID, self::ACTION_ID, self::TOKEN, $exception->getMessage())
            ->andReturnTrue();

        $this->expectExceptionObject($exception);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    #[Test]
    public function an_unexpected_throwable_leaves_the_action_processing(): void
    {
        $exception = new RuntimeException('unexpected');

        $this->expectClaim($this->claim());
        $this->expectSubmission();
        $this->expectCreateThrows($exception);
        $this->actionRepository->shouldNotReceive('returnToPending');
        $this->actionRepository->shouldNotReceive('completeClaim');
        $this->failureHandler->shouldNotReceive('execute');

        $this->expectExceptionObject($exception);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function claim(int $attempts = 1, ?string $externalId = null): ActionClaimDTO
    {
        return new ActionClaimDTO(
            token: self::TOKEN,
            attempts: $attempts,
            externalId: $externalId,
            createdAt: new DateTimeImmutable('2024-01-15 10:00:00'),
        );
    }

    private function expectClaim(ActionClaimDTO $claim): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::HelpScout);
        $this->actionRepository->expects('claim')->with(self::ACTION_ID)->andReturn($claim);
    }

    private function expectSubmission(string $email = 'test@example.com'): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->createSubmission($email));
    }

    private function expectCreate(): void
    {
        $this->transformer->expects('transform')->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')->andReturn(self::CONVERSATION_ID);
    }

    private function expectCreateThrows(Throwable $exception): void
    {
        $this->transformer->expects('transform')->andReturn($this->createCommand());
        $this->helpScoutClient->expects('createConversationFromCustomer')->andThrow($exception);
    }

    private function createSubmission(string $email): ContactSubmission
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
