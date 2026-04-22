<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\UseCases;

use App\Application\ContactSubmission\Results\SubmitContactFormResult;
use App\Application\ContactSubmission\UseCases\SubmitContactFormUseCase;
use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SubmitContactFormUseCase Unit Tests.
 *
 * Tests workflow milestone logging and orchestration:
 * - Entry log emitted before persistence (with hashed email, no PII)
 * - Completion log emitted after dispatch (with submission/action IDs)
 * - Result returned with correct IDs
 */
#[CoversClass(SubmitContactFormUseCase::class)]
final class SubmitContactFormUseCaseTest extends TestCase
{
    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private DatabaseGatewayInterface&MockInterface $database;

    private ContactFormDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SubmitContactFormUseCase $useCase;

    private const string SUBMISSION_ID = 'submission-uuid-abc';

    private const string ACTION_ID = 'action-uuid-def';

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->database = Mockery::mock(DatabaseGatewayInterface::class);
        $this->dispatcher = Mockery::mock(ContactFormDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SubmitContactFormUseCase(
            submissionRepository: $this->submissionRepository,
            actionRepository: $this->actionRepository,
            database: $this->database,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_result_with_submission_and_action_ids(): void
    {
        $this->setupSuccessfulSubmission();

        $result = $this->useCase->execute($this->createSubmission());

        self::assertSame(self::SUBMISSION_ID, $result->submissionId);
        self::assertSame(self::ACTION_ID, $result->actionId);
    }

    #[Test]
    public function execute_dispatches_async_processing_after_transaction(): void
    {
        $this->setupSuccessfulSubmission();

        $this->dispatcher->expects('dispatchContactSubmissionProcessing')
            ->with(self::SUBMISSION_ID, self::ACTION_ID)
            ->once();

        $this->useCase->execute($this->createSubmission());
    }

    /*
    |--------------------------------------------------------------------------
    | Milestone Logging Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_logs_received_milestone_at_entry(): void
    {
        $submission = $this->createSubmission(email: 'customer@example.com');
        $this->setupSuccessfulSubmission();

        $this->logger->expects('info')
            ->with(
                'Contact submission received',
                Mockery::on(static fn(array $ctx): bool => $ctx['reason'] === ContactReason::Other->value
                    && $ctx['email_hash'] === hash('sha256', 'customer@example.com')
                    && $ctx['has_phone'] === false
                    && $ctx['has_order_number'] === false
                    && $ctx['has_product_context'] === false
                    && $ctx['has_shopwired_customer_id'] === false),
            )
            ->once();

        $this->useCase->execute($submission);
    }

    #[Test]
    public function execute_logs_dispatched_milestone_after_completion(): void
    {
        $this->setupSuccessfulSubmission();

        $this->logger->expects('info')
            ->with(
                'Contact submission persisted and dispatched',
                Mockery::on(static fn(array $ctx): bool => $ctx['submission_id'] === self::SUBMISSION_ID
                    && $ctx['action_id'] === self::ACTION_ID),
            )
            ->once();

        $this->useCase->execute($this->createSubmission());
    }

    #[Test]
    public function execute_does_not_log_raw_email_in_received_milestone(): void
    {
        $email = 'private@example.com';
        $submission = $this->createSubmission(email: $email);
        $this->setupSuccessfulSubmission();

        $this->logger->expects('info')
            ->with(
                'Contact submission received',
                Mockery::on(static fn(array $ctx): bool => ! \array_key_exists('email', $ctx)
                    && ! \in_array($email, $ctx, true)),
            )
            ->once();

        $this->useCase->execute($submission);
    }

    #[Test]
    public function execute_logs_presence_booleans_for_optional_fields(): void
    {
        $submission = $this->createSubmission(phone: '07700000000', orderNumber: 'ORD-123');
        $this->setupSuccessfulSubmission();

        $this->logger->expects('info')
            ->with(
                'Contact submission received',
                Mockery::on(static fn(array $ctx): bool => $ctx['has_phone'] === true
                    && $ctx['has_order_number'] === true),
            )
            ->once();

        $this->useCase->execute($submission);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function setupSuccessfulSubmission(): void
    {
        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $fn): SubmitContactFormResult => $fn());

        $this->submissionRepository->expects('save')
            ->andReturn(self::SUBMISSION_ID);

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::HelpScout)
            ->andReturn(self::ACTION_ID);

        $this->dispatcher->allows('dispatchContactSubmissionProcessing');
    }

    private function createSubmission(
        string $email = 'test@example.com',
        ?string $phone = null,
        ?string $orderNumber = null,
    ): ContactSubmission {
        $form = new ContactFormData(
            name: 'Test User',
            email: $email,
            reason: ContactReason::Other,
            message: 'Test message',
            phone: $phone,
            orderNumber: $orderNumber,
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
}
