<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\ContactSubmission\DTOs\ActionClaimDTO;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\UseCases\HandleLeadConversionFailureUseCase;
use App\Application\Conversion\UseCases\ProcessLeadConversionUseCase;
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
use App\Domain\Exceptions\Data\InsufficientDataException;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers ProcessLeadConversionUseCase on the action claim (ADR-0012):
 *  - type guard: a misrouted action throws before any write; a missing one is skipped
 *  - claim outcomes: unclaimable → no upload; past the attempt limit → failed, no upload
 *  - upload outcomes: success completes with 'uploaded'; a transient returns the row to
 *    pending and rethrows with the delay; the last transient, a permanent API failure or
 *    a data fault fails the row; any other throwable leaves the row alone
 *  - a lost claim on any claim-holder write is logged and swallowed
 *
 * The resolver and failure use case are REAL (both `final readonly`); the
 * adapters and repositories are Mockery mocks.
 */
#[CoversNothing]
final class ProcessLeadConversionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string CLAIM_TOKEN = '33333333-3333-4333-8333-333333333333';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const string EMAIL = 'customer@example.com';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private AdPlatformConversionAdapterInterface&MockInterface $googleAdapter;

    private AdPlatformConversionAdapterInterface&MockInterface $bingAdapter;

    private LoggerInterface&MockInterface $logger;

    private ProcessLeadConversionUseCase $useCase;

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
        $this->bingAdapter = Mockery::mock(AdPlatformConversionAdapterInterface::class);
        $this->bingAdapter->allows('platform')->andReturn(AdPlatform::Bing);

        $this->useCase = new ProcessLeadConversionUseCase(
            $this->submissionRepository,
            $this->actionRepository,
            new AdPlatformAdapterResolverService([$this->googleAdapter, $this->bingAdapter]),
            new HandleLeadConversionFailureUseCase($this->actionRepository, $this->logger),
            $this->logger,
        );
    }

    #[Test]
    public function throws_on_a_helpscout_action_before_any_write_or_upload(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::HelpScout);
        $this->actionRepository->shouldNotReceive('claim');
        $this->actionRepository->shouldNotReceive('failClaim');
        $this->googleAdapter->shouldNotReceive('upload');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
            self::fail('Expected ActionTypeMismatchException');
        } catch (ActionTypeMismatchException $e) {
            self::assertSame(self::ACTION_ID, $e->actionId);
            self::assertSame(ActionType::LeadReceived, $e->expected);
            self::assertSame(ActionType::HelpScout, $e->actual);
        }
    }

    #[Test]
    public function skips_a_missing_action_without_claiming(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturnNull();
        $this->actionRepository->shouldNotReceive('claim');
        $this->googleAdapter->shouldNotReceive('upload');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function skips_an_unclaimable_action_without_upload(): void
    {
        $this->expectLeadAction();
        $this->actionRepository->expects('claim')->with(self::ACTION_ID)->andReturnNull();
        $this->submissionRepository->shouldNotReceive('findById');
        $this->googleAdapter->shouldNotReceive('upload');
        $this->actionRepository->shouldNotReceive('completeClaim');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function fails_an_action_past_the_attempt_limit_without_upload(): void
    {
        $this->expectClaim(attempts: 6);
        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, 'Attempt limit exceeded')
            ->andReturnTrue();
        $this->submissionRepository->shouldNotReceive('findById');
        $this->googleAdapter->shouldNotReceive('upload');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function uploads_conversion_via_platform_adapter_and_completes_the_claim(): void
    {
        $submittedAt = new DateTimeImmutable('2026-05-16 10:30:00+00:00');
        $this->expectClaim(attempts: 1);
        $this->expectSubmission($submittedAt);

        $capturedType = null;
        $capturedDto = null;
        $this->googleAdapter->expects('upload')
            ->andReturnUsing(static function (ConversionType $type, ConversionUploadDTO $dto) use (&$capturedType, &$capturedDto): void {
                $capturedType = $type;
                $capturedDto = $dto;
            });
        $this->bingAdapter->shouldNotReceive('upload');

        $this->actionRepository->expects('completeClaim')->with(self::ACTION_ID, self::CLAIM_TOKEN, 'uploaded')->andReturnTrue();

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);

        self::assertSame(ConversionType::LeadReceived, $capturedType);
        self::assertNotNull($capturedDto);
        self::assertSame(self::GCLID, $capturedDto->clickId);
        self::assertSame(self::EMAIL, $capturedDto->email);
        self::assertSame($submittedAt, $capturedDto->convertedAt);
        self::assertNull($capturedDto->value);
        self::assertNull($capturedDto->phone);
    }

    /**
     * The Google transport absorbs an all-duplicate partial failure and returns normally,
     * so a retry whose earlier upload landed completes like a first upload.
     */
    #[Test]
    public function completes_a_retry_whose_upload_returns_normally_after_a_duplicate(): void
    {
        $this->expectClaim(attempts: 3);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload');

        $this->actionRepository->expects('completeClaim')->with(self::ACTION_ID, self::CLAIM_TOKEN, 'uploaded')->andReturnTrue();
        $this->actionRepository->shouldNotReceive('failClaim');
        $this->actionRepository->shouldNotReceive('returnToPending');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function logs_and_swallows_a_lost_claim_on_completion(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload');
        $this->actionRepository->expects('completeClaim')->andReturnFalse();
        $this->logger->expects('warning')->with(Mockery::pattern('/claim lost/'), [
            'submission_id' => self::SUBMISSION_ID,
            'action_id' => self::ACTION_ID,
        ]);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function returns_a_transient_failure_to_pending_and_rethrows_with_the_backoff_delay(): void
    {
        $this->expectClaim(attempts: 2);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $transient = new ExternalServiceUnavailableException('Google Ads');
        $this->googleAdapter->expects('upload')->andThrow($transient);

        $this->actionRepository->expects('returnToPending')->with(self::ACTION_ID, self::CLAIM_TOKEN, 300)->andReturnTrue();
        $this->actionRepository->shouldNotReceive('failClaim');
        $this->actionRepository->shouldNotReceive('completeClaim');

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(300, $e->retryAfter);
            self::assertSame('Google Ads', $e->serviceName);
            self::assertSame($transient, $e->getPrevious());
        }
    }

    #[Test]
    public function honours_the_api_retry_after_on_a_transient_failure(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload')->andThrow(new ExternalServiceUnavailableException('Google Ads', 120));

        $this->actionRepository->expects('returnToPending')->with(self::ACTION_ID, self::CLAIM_TOKEN, 120)->andReturnTrue();

        try {
            $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(120, $e->retryAfter);
        }
    }

    #[Test]
    public function swallows_a_transient_failure_when_the_claim_was_lost(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload')->andThrow(new ExternalServiceUnavailableException('Google Ads'));
        $this->actionRepository->expects('returnToPending')->andReturnFalse();
        $this->logger->expects('warning')->with(Mockery::pattern('/claim lost/'), Mockery::any());

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function fails_the_action_on_a_transient_failure_of_the_last_attempt(): void
    {
        $this->expectClaim(attempts: 5);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload')->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, 'Retries exhausted: External service unavailable')
            ->andReturnTrue();
        $this->actionRepository->shouldNotReceive('returnToPending');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function fails_the_action_and_rethrows_a_permanent_api_failure(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $rejection = new InvalidApiRequestException('Google Ads');
        $this->googleAdapter->expects('upload')->andThrow($rejection);

        $this->actionRepository->expects('failClaim')
            ->with(self::ACTION_ID, self::CLAIM_TOKEN, $rejection->getMessage())
            ->andReturnTrue();
        $this->actionRepository->shouldNotReceive('returnToPending');

        $this->expectExceptionObject($rejection);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function fails_the_action_and_rethrows_a_missing_click_id(): void
    {
        $this->expectClaim(attempts: 1);
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00')));
        $this->bingAdapter->allows('extractClickId')->andReturnNull();
        $this->bingAdapter->shouldNotReceive('upload');

        $this->actionRepository->expects('failClaim')->with(self::ACTION_ID, self::CLAIM_TOKEN, Mockery::type('string'))->andReturnTrue();

        $this->expectException(InsufficientDataException::class);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Bing);
    }

    #[Test]
    public function does_not_rethrow_a_permanent_failure_when_the_claim_was_lost(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload')->andThrow(new InvalidApiRequestException('Google Ads'));
        $this->actionRepository->expects('failClaim')->andReturnFalse();
        $this->logger->expects('warning')->with(Mockery::pattern('/claim lost/'), Mockery::any());
        $this->logger->shouldNotReceive('error');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    #[Test]
    public function leaves_the_action_processing_on_an_unexpected_throwable(): void
    {
        $this->expectClaim(attempts: 1);
        $this->expectSubmission(new DateTimeImmutable('2026-05-16 10:30:00+00:00'));
        $this->googleAdapter->expects('upload')->andThrow(new RuntimeException('boom'));

        $this->actionRepository->shouldNotReceive('failClaim');
        $this->actionRepository->shouldNotReceive('returnToPending');
        $this->actionRepository->shouldNotReceive('completeClaim');

        $this->expectException(RuntimeException::class);

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);
    }

    private function expectLeadAction(): void
    {
        $this->actionRepository->expects('findActionType')->with(self::ACTION_ID)->andReturn(ActionType::LeadReceived);
    }

    private function expectClaim(int $attempts): void
    {
        $this->expectLeadAction();
        $this->actionRepository->expects('claim')->with(self::ACTION_ID)->andReturn(new ActionClaimDTO(
            token: self::CLAIM_TOKEN,
            attempts: $attempts,
            externalId: null,
            createdAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
        ));
    }

    private function expectSubmission(DateTimeImmutable $submittedAt): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission($submittedAt));
    }

    private function makeSubmission(DateTimeImmutable $submittedAt): ContactSubmission
    {
        return new ContactSubmission(
            form: new ContactFormData(
                name: 'Lead Customer',
                email: self::EMAIL,
                reason: ContactReason::QuotationRequest,
                message: 'Please quote for X.',
            ),
            consent: ConsentStatus::denied(),
            attribution: new MarketingAttribution(gclid: self::GCLID),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2026-05-15 09:00:00'),
                ipAddress: '127.0.0.1',
            ),
            submittedAt: $submittedAt,
        );
    }
}
