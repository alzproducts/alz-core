<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\QuoteConversionDetailsDTO;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\UseCases\HandleQuoteConversionFailureUseCase;
use App\Application\Conversion\UseCases\ProcessQuoteConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
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
 * Covers ProcessQuoteConversionUseCase on the action claim (ADR-0012): the type
 * guard, the attempt limit, and the complete / return-to-pending / fail outcomes
 * of the upload. The quote upload carries the payload's value and converted-at.
 *
 * The resolver and failure use case are REAL (both `final readonly`); the
 * adapter and repositories are Mockery mocks.
 */
#[CoversNothing]
final class ProcessQuoteConversionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string CLAIM_TOKEN = '33333333-3333-4333-8333-333333333333';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const float VALUE = 149.99;

    private const string CONVERTED_AT = '2026-05-18T10:00:00+00:00';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private AdPlatformConversionAdapterInterface&MockInterface $googleAdapter;

    private LoggerInterface&MockInterface $logger;

    private ProcessQuoteConversionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->googleAdapter = Mockery::mock(AdPlatformConversionAdapterInterface::class);
        $this->googleAdapter->allows('platform')->andReturn(AdPlatform::Google);
        $this->googleAdapter->allows('extractClickId')->andReturn(self::GCLID);

        $this->useCase = new ProcessQuoteConversionUseCase(
            $this->submissionRepository,
            $this->actionRepository,
            new AdPlatformAdapterResolverService([$this->googleAdapter]),
            new HandleQuoteConversionFailureUseCase($this->actionRepository, $this->logger),
            $this->logger,
        );
    }

    #[Test]
    public function throws_on_a_lead_action_before_any_write_or_upload(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::LeadReceived);
        $this->actionRepository->shouldNotReceive('claim');
        $this->googleAdapter->shouldNotReceive('upload');

        try {
            $this->execute();
            self::fail('Expected ActionTypeMismatchException');
        } catch (ActionTypeMismatchException $e) {
            self::assertSame(ActionType::QuoteIssued, $e->expected);
            self::assertSame(ActionType::LeadReceived, $e->actual);
        }
    }

    #[Test]
    public function skips_an_unclaimable_action_without_upload(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::QuoteIssued);
        $this->actionRepository->expects('claim')->with(self::ACTION_ID)->andReturnNull();
        $this->googleAdapter->shouldNotReceive('upload');

        $this->execute();
    }

    #[Test]
    public function fails_an_action_past_the_attempt_limit_without_upload(): void
    {
        $this->expectClaim(attempts: 6);
        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, 'Attempt limit exceeded')
            ->andReturnTrue();
        $this->googleAdapter->shouldNotReceive('upload');

        $this->execute();
    }

    #[Test]
    public function uploads_the_quote_value_and_time_and_completes_the_claim(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission();

        $capturedType = null;
        $capturedDto = null;
        $this->googleAdapter->expects('upload')
            ->andReturnUsing(static function (ConversionType $type, ConversionUploadDTO $dto) use (&$capturedType, &$capturedDto): void {
                $capturedType = $type;
                $capturedDto = $dto;
            });

        $this->actionRepository->expects('completeClaim')->with(self::ACTION_ID, self::CLAIM_TOKEN, 'uploaded')->andReturnTrue();

        $this->execute();

        self::assertSame(ConversionType::QuoteIssued, $capturedType);
        self::assertNotNull($capturedDto);
        self::assertSame(self::GCLID, $capturedDto->clickId);
        self::assertSame(self::CONVERTED_AT, $capturedDto->convertedAt->format(DateTimeInterface::ATOM));
        self::assertSame(self::VALUE, $capturedDto->value?->toNet());
    }

    #[Test]
    public function returns_a_transient_failure_to_pending_and_rethrows_with_the_backoff_delay(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission();
        $this->googleAdapter->expects('upload')->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->actionRepository->expects('returnToPending')->with(self::ACTION_ID, self::CLAIM_TOKEN, 60)->andReturnTrue();
        $this->actionRepository->shouldNotReceive('failClaim');

        try {
            $this->execute();
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(60, $e->retryAfter);
        }
    }

    #[Test]
    public function fails_the_action_on_a_transient_failure_of_the_last_attempt(): void
    {
        $this->expectClaim(attempts: 5);
        $this->expectSubmission();
        $this->googleAdapter->expects('upload')->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, 'Retries exhausted: External service unavailable')
            ->andReturnTrue();
        $this->actionRepository->shouldNotReceive('returnToPending');

        $this->execute();
    }

    #[Test]
    public function fails_the_action_and_rethrows_a_permanent_api_failure(): void
    {
        $this->expectClaim(attempts: 2);
        $this->expectSubmission();
        $rejection = new InvalidApiRequestException('Google Ads');
        $this->googleAdapter->expects('upload')->andThrow($rejection);

        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, $rejection->getMessage())
            ->andReturnTrue();

        $this->expectExceptionObject($rejection);

        $this->execute();
    }

    #[Test]
    public function fails_the_action_and_rethrows_a_malformed_converted_at(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission();
        $this->googleAdapter->shouldNotReceive('upload');

        $this->actionRepository->expects('failClaim')->with(self::ACTION_ID, self::CLAIM_TOKEN, Mockery::type('string'))->andReturnTrue();

        $this->expectException(MalformedStoredDataException::class);

        $this->useCase->execute(
            self::SUBMISSION_ID,
            self::ACTION_ID,
            new QuoteConversionDetailsDTO(self::VALUE, 'not-a-date'),
            AdPlatform::Google,
        );
    }

    #[Test]
    public function logs_and_swallows_a_lost_claim_on_completion(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission();
        $this->googleAdapter->expects('upload');
        $this->actionRepository->expects('completeClaim')->andReturnFalse();
        $this->logger->expects('warning')->with(Mockery::pattern('/claim lost/'), [
            'submission_id' => self::SUBMISSION_ID,
            'action_id' => self::ACTION_ID,
        ]);

        $this->execute();
    }

    private function execute(): void
    {
        $this->useCase->execute(
            self::SUBMISSION_ID,
            self::ACTION_ID,
            new QuoteConversionDetailsDTO(self::VALUE, self::CONVERTED_AT),
            AdPlatform::Google,
        );
    }

    private function expectClaim(int $attempts): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::QuoteIssued);
        $this->actionRepository->expects('claim')->with(self::ACTION_ID)->andReturn(new ActionClaimDTO(
            token: self::CLAIM_TOKEN,
            attempts: $attempts,
            externalId: null,
            createdAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
        ));
    }

    private function expectSubmission(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn(new ContactSubmission(
                form: new ContactFormData(
                    name: 'Quote Customer',
                    email: 'customer@example.com',
                    reason: ContactReason::QuotationRequest,
                    message: 'Please quote for X.',
                ),
                consent: ConsentStatus::denied(),
                attribution: new MarketingAttribution(gclid: self::GCLID),
                context: new SubmissionContext(
                    clientTimestamp: new DateTimeImmutable('2026-05-15 09:00:00'),
                    ipAddress: '127.0.0.1',
                ),
            ));
    }
}
