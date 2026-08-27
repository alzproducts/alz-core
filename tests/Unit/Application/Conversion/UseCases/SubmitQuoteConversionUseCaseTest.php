<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Commands\QuoteConversionCommand;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\UseCases\SubmitQuoteConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Conversion\Enums\ConversionType;
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
 * Covers SubmitQuoteConversionUseCase::execute() under the adapter seam (COR-207).
 * Only Google supports quote conversions; Bing does not:
 * - 404 when the submission row is missing
 * - 422 when the submission has neither gclid nor msclkid
 * - graceful skip (zero rows, no dispatch, no throw) when the only click ID is
 *   msclkid — Bing cannot receive a quote
 * - 422 when there's no completed LeadReceived action
 * - 409 when a quote action already exists for the submission
 * - 202 happy path (gclid → one Google dispatch)
 * - both click IDs → Google dispatched once, Bing skipped
 *
 * The resolver is a REAL {@see AdPlatformAdapterResolverService} wrapping fake
 * in-memory adapters; the dispatcher, repositories remain mocks.
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

    private const string MSCLKID = 'cdd4afcccb1c9a4cad9544dd7e5006d5-1';

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
            adapterResolver: $this->buildResolver(),
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
    public function execute_skips_msclkid_only_submission_without_dispatch_or_throw(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: self::MSCLKID));

        $errorLogged = false;
        $this->logger->expects('error')->once()
            ->andReturnUsing(static function () use (&$errorLogged): void {
                $errorLogged = true;
            });

        $this->actionRepository->shouldNotReceive('hasCompletedAction');
        $this->actionRepository->shouldNotReceive('create');
        $this->dispatcher->shouldNotReceive('dispatchQuoteConversion');

        $this->useCase->execute(self::SUBMISSION_ID, self::VALUE, self::CONVERTED_AT);

        self::assertTrue($errorLogged, 'expected a graceful-skip error log when no platform supports the quote');
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
        self::assertSame(AdPlatform::Google, $captured->platform);
    }

    #[Test]
    public function execute_dispatches_only_google_and_skips_bing_when_both_click_ids_present(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: self::MSCLKID));

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
        self::assertSame(AdPlatform::Google, $captured->platform);
    }

    private function buildResolver(): AdPlatformAdapterResolverService
    {
        $google = new class implements AdPlatformConversionAdapterInterface {
            public function platform(): AdPlatform
            {
                return AdPlatform::Google;
            }

            public function supports(ConversionType $type): bool
            {
                return true;
            }

            public function extractClickId(MarketingAttribution $attribution): ?string
            {
                return $attribution->gclid;
            }

            public function upload(ConversionType $type, ConversionUploadDTO $data): void {}
        };

        $bing = new class implements AdPlatformConversionAdapterInterface {
            public function platform(): AdPlatform
            {
                return AdPlatform::Bing;
            }

            public function supports(ConversionType $type): bool
            {
                return $type === ConversionType::LeadReceived;
            }

            public function extractClickId(MarketingAttribution $attribution): ?string
            {
                return $attribution->msclkid;
            }

            public function upload(ConversionType $type, ConversionUploadDTO $data): void {}
        };

        return new AdPlatformAdapterResolverService([$google, $bing]);
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
            attribution: new MarketingAttribution(
                gclid: $gclid,
                msclkid: $msclkid,
            ),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2026-05-15 09:00:00'),
                ipAddress: '127.0.0.1',
            ),
        );
    }
}
