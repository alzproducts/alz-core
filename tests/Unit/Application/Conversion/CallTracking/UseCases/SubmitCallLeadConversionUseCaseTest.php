<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallConversionDispatcherInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\CallTracking\Commands\CallLeadConversionCommand;
use App\Application\Conversion\CallTracking\UseCases\SubmitCallLeadConversionUseCase;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
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
 * Covers the COR-180 annotation dual-write on call lead conversion:
 *  - 400 when neither gclid nor msclkid is present (no annotation, no dispatch)
 *  - annotation upsert is keyed by the CALL id (not the visit id) so the row matches the view
 *  - is_potential_quote flows through to the annotation command (true and false)
 */
#[CoversNothing]
final class SubmitCallLeadConversionUseCaseTest extends TestCase
{
    private const string VISIT_ID = '11111111-1111-4111-8111-111111111111';

    private const string CALL_ID = '99999999-9999-4999-8999-999999999999';

    private const string GOOGLE_ACTION_ID = '22222222-2222-4222-8222-222222222222';

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
        $this->dispatcher->shouldNotReceive('dispatchGoogleCallLeadConversion');
        $this->dispatcher->shouldNotReceive('dispatchBingCallLeadConversion');

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
        $this->dispatcher->expects('dispatchGoogleCallLeadConversion')
            ->andReturnUsing(static function (CallLeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });
        $this->dispatcher->shouldNotReceive('dispatchBingCallLeadConversion');

        $this->execute(gclid: self::GCLID, msclkid: null, isPotentialQuote: true);

        self::assertNotNull($captured);
        self::assertSame(self::VISIT_ID, $captured->visitId->value);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured->actionId->value);
        self::assertSame(self::CALLER_PHONE, $captured->callerPhone->value);
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

        $this->dispatcher->expects('dispatchGoogleCallLeadConversion');

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
