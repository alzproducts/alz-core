<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Contracts\Conversion\CallTracking\CallConversionDispatcherInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;
use App\Application\Conversion\CallTracking\UseCases\SubmitCallLeadConversionUseCase;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\ValueObjects\IpAddress;
use App\Domain\ValueObjects\Uuid;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Covers the adapter-seam call-lead fan-out (COR-207):
 *  - 400 when neither gclid nor msclkid is present (no annotation, no dispatch)
 *  - annotation upsert is keyed by the CALL id (not the visit id) so the row matches the view
 *  - gclid-only → one Google action, one Google dispatch
 *  - both click IDs → two action rows, two dispatches (one per platform)
 *  - is_potential_quote flows through to the annotation command
 *
 * The resolver is a REAL {@see AdPlatformAdapterResolverService} wrapping fake
 * in-memory adapters; the dispatcher, repositories and gateway remain mocks.
 */
#[CoversNothing]
final class SubmitCallLeadConversionUseCaseTest extends TestCase
{
    private const string VISIT_ID = '11111111-1111-4111-8111-111111111111';

    private const string CALL_ID = '99999999-9999-4999-8999-999999999999';

    private const string GOOGLE_ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string BING_ACTION_ID = '33333333-3333-4333-8333-333333333333';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const string MSCLKID = 'cdd4afcccb1c9a4cad9544dd7e5006d5-1';

    private const string TRACKING_NUMBER = '+441234567890';

    private const string CALLER_PHONE = '+447911123456';

    private CallTrackingActionRepositoryInterface&MockInterface $actionRepository;

    private PotentialConversionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private DatabaseGatewayInterface&MockInterface $database;

    private CallConversionDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SubmitCallLeadConversionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->actionRepository = Mockery::mock(CallTrackingActionRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(PotentialConversionAnnotationRepositoryInterface::class);
        $this->database = Mockery::mock(DatabaseGatewayInterface::class);
        $this->dispatcher = Mockery::mock(CallConversionDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SubmitCallLeadConversionUseCase(
            actionRepository: $this->actionRepository,
            annotationRepository: $this->annotationRepository,
            database: $this->database,
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
    public function throws_insufficient_data_when_neither_click_id_present(): void
    {
        $this->database->shouldNotReceive('transact');
        $this->actionRepository->shouldNotReceive('create');
        $this->annotationRepository->shouldNotReceive('upsert');
        $this->dispatcher->shouldNotReceive('dispatchCallLeadConversion');

        try {
            $this->execute(gclid: null, msclkid: null, isPotentialQuote: true);
            self::fail('Expected InsufficientDataException');
        } catch (InsufficientDataException $e) {
            self::assertSame('CallTrackingVisit', $e->entityType);
            self::assertSame('a gclid or msclkid for conversion tracking', $e->requirement);
        }
    }

    #[Test]
    public function writes_annotation_keyed_by_call_id_and_dispatches_google(): void
    {
        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->with(Mockery::on(static fn(Uuid $visitId): bool => $visitId->value === self::VISIT_ID), AdPlatform::Google)
            ->andReturn(new Uuid(self::GOOGLE_ACTION_ID));

        $this->annotationRepository->expects('upsert')
            ->with(Mockery::on(static fn(UpsertAnnotationCommand $cmd): bool => $cmd->sourceId === self::CALL_ID
                && $cmd->valuesToSet === ['is_potential_quote' => true]
                && $cmd->columnsToClear === []));

        $captured = null;
        $this->dispatcher->expects('dispatchCallLeadConversion')
            ->andReturnUsing(static function (CallLeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });

        $this->execute(gclid: self::GCLID, msclkid: null, isPotentialQuote: true);

        self::assertNotNull($captured);
        self::assertSame(self::VISIT_ID, $captured->visitId->value);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured->actionId->value);
        self::assertSame(self::CALLER_PHONE, $captured->callerPhone->value);
        self::assertSame(AdPlatform::Google, $captured->platform);
    }

    #[Test]
    public function fans_out_to_both_platforms_when_both_click_ids_present(): void
    {
        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->with(Mockery::on(static fn(Uuid $visitId): bool => $visitId->value === self::VISIT_ID), AdPlatform::Google)
            ->andReturn(new Uuid(self::GOOGLE_ACTION_ID));
        $this->actionRepository->expects('create')
            ->with(Mockery::on(static fn(Uuid $visitId): bool => $visitId->value === self::VISIT_ID), AdPlatform::Bing)
            ->andReturn(new Uuid(self::BING_ACTION_ID));

        $this->annotationRepository->expects('upsert');

        $captured = [];
        $this->dispatcher->expects('dispatchCallLeadConversion')
            ->twice()
            ->andReturnUsing(static function (CallLeadConversionCommand $cmd) use (&$captured): void {
                $captured[$cmd->platform->value] = $cmd;
            });

        $this->execute(gclid: self::GCLID, msclkid: self::MSCLKID, isPotentialQuote: true);

        self::assertCount(2, $captured);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured[AdPlatform::Google->value]->actionId->value);
        self::assertSame(self::BING_ACTION_ID, $captured[AdPlatform::Bing->value]->actionId->value);
    }

    #[Test]
    public function writes_is_potential_quote_false_when_supplied_false(): void
    {
        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->andReturn(new Uuid(self::GOOGLE_ACTION_ID));

        $this->annotationRepository->expects('upsert')
            ->with(Mockery::on(static fn(UpsertAnnotationCommand $cmd): bool => $cmd->sourceId === self::CALL_ID
                && $cmd->valuesToSet === ['is_potential_quote' => false]));

        $this->dispatcher->expects('dispatchCallLeadConversion');

        $this->execute(gclid: self::GCLID, msclkid: null, isPotentialQuote: false);
    }

    private function execute(?string $gclid, ?string $msclkid, bool $isPotentialQuote): void
    {
        $this->useCase->execute(
            $this->makeVisit($gclid, $msclkid),
            new Uuid(self::CALL_ID),
            PhoneNumberE164::from(self::CALLER_PHONE),
            $isPotentialQuote,
        );
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

    private function makeVisit(?string $gclid, ?string $msclkid): CallTrackingVisit
    {
        return new CallTrackingVisit(
            attribution: new MarketingAttribution(gclid: $gclid, msclkid: $msclkid),
            marketingConsentGranted: true,
            trackingNumberShown: PhoneNumberE164::from(self::TRACKING_NUMBER),
            ipAddress: IpAddress::from('127.0.0.1'),
            userAgent: null,
            id: new Uuid(self::VISIT_ID),
            createdAt: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
        );
    }
}
