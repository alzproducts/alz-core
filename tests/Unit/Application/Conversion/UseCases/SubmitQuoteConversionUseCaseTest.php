<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\UseCases\SubmitQuoteConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;
use DateTimeInterface;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Covers the four status-code branches of SubmitQuoteConversionUseCase::execute():
 * - 404 when the submission row is missing
 * - 422 when the submission has no gclid
 * - 422 when there's no completed LeadReceived action
 * - 409 when a quote action already exists for the submission
 * - 202 happy path (command unwrap + dispatch)
 *
 * These are the branches the project's TestingStrategy flags as worth covering
 * in the Application layer ("business workflow branches, orchestration decisions,
 * error handling paths").
 */
#[CoversNothing]
final class SubmitQuoteConversionUseCaseTest extends TestCase
{
    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ConversionDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SubmitQuoteConversionUseCase $useCase;

    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const float VALUE = 149.99;

    private const string CONVERTED_AT = '2026-05-18T10:00:00+00:00';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(ConversionDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SubmitQuoteConversionUseCase(
            submissionRepository: $this->submissionRepository,
            actionRepository: $this->actionRepository,
            dispatcher: $this->dispatcher,
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
    public function execute_throws_record_not_found_when_submission_missing(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andThrow(new RecordNotFoundException('contact_submission', self::SUBMISSION_ID));

        $this->actionRepository->shouldNotReceive('hasCompletedAction');
        $this->actionRepository->shouldNotReceive('create');
        $this->dispatcher->shouldNotReceive('dispatchQuoteConversion');

        $this->expectException(RecordNotFoundException::class);

        $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);
    }

    #[Test]
    public function execute_throws_insufficient_data_when_both_click_ids_missing(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: null));

        $this->actionRepository->shouldNotReceive('hasCompletedAction');
        $this->actionRepository->shouldNotReceive('create');
        $this->dispatcher->shouldNotReceive('dispatchQuoteConversion');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);
            self::fail('Expected InsufficientDataException');
        } catch (InsufficientDataException $e) {
            self::assertSame('ContactSubmission', $e->entityType);
            self::assertSame('a gclid or msclkid for conversion tracking', $e->requirement);
        }
    }

    #[Test]
    public function execute_accepts_msclkid_only_submission_and_dispatches_with_google_platform(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: 'BingMsclkid'));

        $this->actionRepository->expects('hasCompletedAction')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(true);

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued, AdPlatform::Google)
            ->andReturn(self::ACTION_ID);

        $this->dispatcher->expects('dispatchQuoteConversion');

        $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);
    }

    #[Test]
    public function execute_throws_insufficient_data_when_no_completed_lead(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->actionRepository->expects('hasCompletedAction')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(false);

        $this->actionRepository->shouldNotReceive('create');
        $this->dispatcher->shouldNotReceive('dispatchQuoteConversion');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);
            self::fail('Expected InsufficientDataException');
        } catch (InsufficientDataException $e) {
            self::assertSame('ContactSubmission', $e->entityType);
            self::assertSame('a completed lead action before issuing a quote', $e->requirement);
        }
    }

    #[Test]
    public function execute_propagates_duplicate_record_when_quote_action_exists(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->actionRepository->expects('hasCompletedAction')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(true);

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued, AdPlatform::Google)
            ->andThrow(new DuplicateRecordException(
                'contact_submission_actions',
                'contact_submission_actions_submission_action_unique',
            ));

        $this->dispatcher->shouldNotReceive('dispatchQuoteConversion');

        $this->expectException(DuplicateRecordException::class);

        $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);
    }

    #[Test]
    public function execute_dispatches_command_with_unwrapped_value_objects_on_happy_path(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->actionRepository->expects('hasCompletedAction')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived)
            ->andReturn(true);

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::QuoteIssued, AdPlatform::Google)
            ->andReturn(self::ACTION_ID);

        $captured = null;
        $this->dispatcher->expects('dispatchQuoteConversion')
            ->once()
            ->andReturnUsing(static function (QuoteConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });

        $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);

        self::assertNotNull($captured);
        self::assertSame(self::SUBMISSION_ID, $captured->submissionId->value);
        self::assertSame(self::ACTION_ID, $captured->actionId->value);
        self::assertSame(self::VALUE, $captured->value->toNet());
        self::assertSame(self::CONVERTED_AT, $captured->convertedAt->format(DateTimeInterface::ATOM));
    }

    private function makeSubmission(?string $gclid, ?string $msclkid): ContactSubmission
    {
        return new ContactSubmission(
            form: new ContactFormData(
                name: 'Quote Customer',
                email: 'customer@example.com',
                reason: ContactReason::QuotationRequest,
                message: 'Please quote for X.',
            ),
            consent: ConsentStatus::denied(),
            attribution: new MarketingAttribution(gclid: $gclid, msclkid: $msclkid),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2026-05-15 09:00:00'),
                ipAddress: '127.0.0.1',
            ),
        );
    }
}
