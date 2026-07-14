<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\UseCases\ProcessLeadConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Conversion\Enums\ConversionType;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Covers the async upload pipeline of ProcessLeadConversionUseCase (COR-207):
 *  - idempotent early-return when the action is already terminal (no upload,
 *    no status writes)
 *  - happy path: routes through the resolver's adapter for the given platform,
 *    uploads a LeadReceived conversion carrying the adapter's extracted click ID,
 *    then marks the action completed with the 'uploaded' receipt
 *
 * The resolver is a REAL {@see AdPlatformAdapterResolverService} (it is
 * `final readonly`); the adapters it holds are Mockery mocks of the interface,
 * so the upload payload can be asserted while platform routing stays real.
 */
#[CoversNothing]
final class ProcessLeadConversionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string ACTION_ID = '22222222-2222-4222-8222-222222222222';

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
        $this->bingAdapter = Mockery::mock(AdPlatformConversionAdapterInterface::class);
        $this->bingAdapter->allows('platform')->andReturn(AdPlatform::Bing);

        $this->useCase = new ProcessLeadConversionUseCase(
            $this->submissionRepository,
            $this->actionRepository,
            new AdPlatformAdapterResolverService([$this->googleAdapter, $this->bingAdapter]),
            $this->logger,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function returns_early_when_action_already_terminal(): void
    {
        $this->actionRepository->expects('getStatus')
            ->with(self::ACTION_ID)
            ->andReturn(ActionStatus::Completed);

        $skipLogged = false;
        $this->logger->allows('info')->andReturnUsing(static function (string $message) use (&$skipLogged): void {
            if (str_contains($message, 'already terminal')) {
                $skipLogged = true;
            }
        });

        $this->actionRepository->shouldNotReceive('incrementAttempts');
        $this->actionRepository->shouldNotReceive('markProcessing');
        $this->actionRepository->shouldNotReceive('markCompleted');
        $this->submissionRepository->shouldNotReceive('findById');
        $this->googleAdapter->shouldNotReceive('upload');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);

        self::assertTrue($skipLogged, 'expected the idempotency skip to be logged');
    }

    #[Test]
    public function uploads_conversion_via_platform_adapter_and_marks_completed(): void
    {
        $submittedAt = new DateTimeImmutable('2026-05-16 10:30:00+00:00');

        $this->actionRepository->expects('getStatus')
            ->with(self::ACTION_ID)
            ->andReturn(ActionStatus::Pending);
        $this->actionRepository->expects('incrementAttempts')->with(self::ACTION_ID);
        $this->actionRepository->expects('markProcessing')->with(self::ACTION_ID);

        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission($submittedAt));

        $this->googleAdapter->allows('extractClickId')->andReturn(self::GCLID);

        $capturedType = null;
        $capturedDto = null;
        $this->googleAdapter->expects('upload')
            ->andReturnUsing(static function (ConversionType $type, ConversionUploadDTO $dto) use (&$capturedType, &$capturedDto): void {
                $capturedType = $type;
                $capturedDto = $dto;
            });
        $this->bingAdapter->shouldNotReceive('upload');

        $this->actionRepository->expects('markCompleted')->with(self::ACTION_ID, 'uploaded');

        $this->useCase->execute(self::SUBMISSION_ID, self::ACTION_ID, AdPlatform::Google);

        self::assertSame(ConversionType::LeadReceived, $capturedType);
        self::assertNotNull($capturedDto);
        self::assertSame(self::GCLID, $capturedDto->clickId);
        self::assertSame(self::EMAIL, $capturedDto->email);
        self::assertSame($submittedAt, $capturedDto->convertedAt);
        self::assertNull($capturedDto->value);
        self::assertNull($capturedDto->phone);
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
