<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Contracts\Conversion\PotentialConversion\PotentialConversionAnnotationRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\PotentialConversion\Commands\UpsertAnnotationCommand;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\Conversion\UseCases\SubmitLeadConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\ValueObjects\Uuid;
use Closure;
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
 * Covers the adapter-seam fan-out (COR-207):
 *  - 400 when neither gclid nor msclkid is present
 *  - gclid-only → one Google action, one Google dispatch
 *  - msclkid-only → one Bing action, one Bing dispatch
 *  - both click IDs → two action rows, two dispatches (one per platform)
 *  - is_potential_quote=false flows through to the annotation command
 *  - transaction failure short-circuits the dispatcher
 *
 * The resolver is a REAL {@see AdPlatformAdapterResolverService} wrapping fake
 * in-memory adapters (it is `final readonly` and cannot be mocked); the
 * dispatcher, repositories and gateway remain Mockery mocks.
 */
#[CoversNothing]
final class SubmitLeadConversionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string GOOGLE_ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string BING_ACTION_ID = '33333333-3333-4333-8333-333333333333';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const string MSCLKID = 'cdd4afcccb1c9a4cad9544dd7e5006d5-1';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private PotentialConversionAnnotationRepositoryInterface&MockInterface $annotationRepository;

    private DatabaseGatewayInterface&MockInterface $database;

    private ConversionDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private SubmitLeadConversionUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionRepository = Mockery::mock(ContactSubmissionRepositoryInterface::class);
        $this->actionRepository = Mockery::mock(ContactSubmissionActionRepositoryInterface::class);
        $this->annotationRepository = Mockery::mock(PotentialConversionAnnotationRepositoryInterface::class);
        $this->database = Mockery::mock(DatabaseGatewayInterface::class);
        $this->dispatcher = Mockery::mock(ConversionDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SubmitLeadConversionUseCase(
            submissionRepository: $this->submissionRepository,
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
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: null));

        $this->database->shouldNotReceive('transact');
        $this->actionRepository->shouldNotReceive('create');
        $this->annotationRepository->shouldNotReceive('upsert');
        $this->dispatcher->shouldNotReceive('dispatchLeadConversion');

        try {
            $this->useCase->execute(new Uuid(self::SUBMISSION_ID), true);
            self::fail('Expected InsufficientDataException');
        } catch (InsufficientDataException $e) {
            self::assertSame('ContactSubmission', $e->entityType);
            self::assertSame('a gclid or msclkid for conversion tracking', $e->requirement);
        }
    }

    #[Test]
    public function dispatches_only_google_when_gclid_present_and_msclkid_absent(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived, AdPlatform::Google)
            ->andReturn(self::GOOGLE_ACTION_ID);

        $this->annotationRepository->expects('upsert')
            ->with(Mockery::on(static fn(UpsertAnnotationCommand $cmd): bool => $cmd->sourceId === self::SUBMISSION_ID
                && $cmd->valuesToSet === ['is_potential_quote' => true]
                && $cmd->columnsToClear === []));

        $captured = null;
        $this->dispatcher->expects('dispatchLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });

        $this->useCase->execute(new Uuid(self::SUBMISSION_ID), true);

        self::assertNotNull($captured);
        self::assertSame(self::SUBMISSION_ID, $captured->submissionId->value);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured->actionId->value);
        self::assertSame(AdPlatform::Google, $captured->platform);
    }

    #[Test]
    public function dispatches_only_bing_when_msclkid_present_and_gclid_absent(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: self::MSCLKID));

        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived, AdPlatform::Bing)
            ->andReturn(self::BING_ACTION_ID);

        $this->annotationRepository->expects('upsert');

        $captured = null;
        $this->dispatcher->expects('dispatchLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });

        $this->useCase->execute(new Uuid(self::SUBMISSION_ID), true);

        self::assertNotNull($captured);
        self::assertSame(self::SUBMISSION_ID, $captured->submissionId->value);
        self::assertSame(self::BING_ACTION_ID, $captured->actionId->value);
        self::assertSame(AdPlatform::Bing, $captured->platform);
    }

    #[Test]
    public function fans_out_to_both_platforms_when_both_click_ids_present(): void
    {
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: self::MSCLKID));

        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived, AdPlatform::Google)
            ->andReturn(self::GOOGLE_ACTION_ID);
        $this->actionRepository->expects('create')
            ->with(self::SUBMISSION_ID, ActionType::LeadReceived, AdPlatform::Bing)
            ->andReturn(self::BING_ACTION_ID);

        $this->annotationRepository->expects('upsert');

        $captured = [];
        $this->dispatcher->expects('dispatchLeadConversion')
            ->twice()
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$captured): void {
                $captured[$cmd->platform->value] = $cmd;
            });

        $this->useCase->execute(new Uuid(self::SUBMISSION_ID), true);

        self::assertCount(2, $captured);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured[AdPlatform::Google->value]->actionId->value);
        self::assertSame(self::BING_ACTION_ID, $captured[AdPlatform::Bing->value]->actionId->value);
    }

    #[Test]
    public function writes_is_potential_quote_false_when_supplied_false(): void
    {
        $this->submissionRepository->expects('findById')
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->database->expects('transact')
            ->andReturnUsing(static fn(Closure $cb): mixed => $cb());

        $this->actionRepository->expects('create')
            ->andReturn(self::GOOGLE_ACTION_ID);

        $this->annotationRepository->expects('upsert')
            ->with(Mockery::on(static fn(UpsertAnnotationCommand $cmd): bool => $cmd->valuesToSet === ['is_potential_quote' => false]));

        $this->dispatcher->expects('dispatchLeadConversion');

        $this->useCase->execute(new Uuid(self::SUBMISSION_ID), false);
    }

    #[Test]
    public function does_not_dispatch_when_transaction_fails(): void
    {
        $this->submissionRepository->expects('findById')
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->database->expects('transact')
            ->andThrow(new RuntimeException('boom'));

        $this->dispatcher->shouldNotReceive('dispatchLeadConversion');

        $this->expectException(RuntimeException::class);

        $this->useCase->execute(new Uuid(self::SUBMISSION_ID), true);
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
                name: 'Lead Customer',
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
