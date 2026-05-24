<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\UseCases;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\Commands\LeadConversionCommand;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\UseCases\SubmitLeadConversionUseCase;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\ValueObjects\Guid;
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
 * Covers the COR-167 fan-out:
 *  - 400 when neither gclid nor msclkid is present
 *  - happy path: gclid-only → one Google action, one Google dispatch
 *  - happy path: msclkid-only → one Bing action, one Bing dispatch
 *  - happy path: both click IDs → two action rows, two dispatches
 *  - is_potential_quote=false flows through to the annotation command
 *  - transaction failure short-circuits the dispatcher
 */
#[CoversNothing]
final class SubmitLeadConversionUseCaseTest extends TestCase
{
    private const string SUBMISSION_ID = '11111111-1111-4111-8111-111111111111';

    private const string GOOGLE_ACTION_ID = '22222222-2222-4222-8222-222222222222';

    private const string BING_ACTION_ID = '33333333-3333-4333-8333-333333333333';

    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const string MSCLKID = 'BingMsclkidTestValue678';

    private ContactSubmissionRepositoryInterface&MockInterface $submissionRepository;

    private ContactSubmissionActionRepositoryInterface&MockInterface $actionRepository;

    private ContactSubmissionAnnotationRepositoryInterface&MockInterface $annotationRepository;

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
        $this->annotationRepository = Mockery::mock(ContactSubmissionAnnotationRepositoryInterface::class);
        $this->database = Mockery::mock(DatabaseGatewayInterface::class);
        $this->dispatcher = Mockery::mock(ConversionDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SubmitLeadConversionUseCase(
            submissionRepository: $this->submissionRepository,
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
        $this->submissionRepository->expects('findById')
            ->with(self::SUBMISSION_ID)
            ->andReturn($this->makeSubmission(gclid: null, msclkid: null));

        $this->database->shouldNotReceive('transact');
        $this->actionRepository->shouldNotReceive('create');
        $this->annotationRepository->shouldNotReceive('upsert');
        $this->dispatcher->shouldNotReceive('dispatchLeadConversion');
        $this->dispatcher->shouldNotReceive('dispatchBingLeadConversion');

        try {
            $this->useCase->execute(new Guid(self::SUBMISSION_ID), true);
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
            ->with(Mockery::on(static fn(UpsertAnnotationCommand $cmd): bool => $cmd->contactSubmissionId === self::SUBMISSION_ID
                && $cmd->valuesToSet === ['is_potential_quote' => true]
                && $cmd->columnsToClear === []));

        $captured = null;
        $this->dispatcher->expects('dispatchLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });
        $this->dispatcher->shouldNotReceive('dispatchBingLeadConversion');

        $this->useCase->execute(new Guid(self::SUBMISSION_ID), true);

        self::assertNotNull($captured);
        self::assertSame(self::SUBMISSION_ID, $captured->submissionId->value);
        self::assertSame(self::GOOGLE_ACTION_ID, $captured->actionId->value);
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
        $this->dispatcher->expects('dispatchBingLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$captured): void {
                $captured = $cmd;
            });
        $this->dispatcher->shouldNotReceive('dispatchLeadConversion');

        $this->useCase->execute(new Guid(self::SUBMISSION_ID), true);

        self::assertNotNull($captured);
        self::assertSame(self::SUBMISSION_ID, $captured->submissionId->value);
        self::assertSame(self::BING_ACTION_ID, $captured->actionId->value);
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

        $googleCmd = null;
        $bingCmd = null;
        $this->dispatcher->expects('dispatchLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$googleCmd): void {
                $googleCmd = $cmd;
            });
        $this->dispatcher->expects('dispatchBingLeadConversion')
            ->andReturnUsing(static function (LeadConversionCommand $cmd) use (&$bingCmd): void {
                $bingCmd = $cmd;
            });

        $this->useCase->execute(new Guid(self::SUBMISSION_ID), true);

        self::assertNotNull($googleCmd);
        self::assertSame(self::GOOGLE_ACTION_ID, $googleCmd->actionId->value);
        self::assertNotNull($bingCmd);
        self::assertSame(self::BING_ACTION_ID, $bingCmd->actionId->value);
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

        $this->useCase->execute(new Guid(self::SUBMISSION_ID), false);
    }

    #[Test]
    public function does_not_dispatch_when_transaction_fails(): void
    {
        $this->submissionRepository->expects('findById')
            ->andReturn($this->makeSubmission(gclid: self::GCLID, msclkid: null));

        $this->database->expects('transact')
            ->andThrow(new RuntimeException('boom'));

        $this->dispatcher->shouldNotReceive('dispatchLeadConversion');
        $this->dispatcher->shouldNotReceive('dispatchBingLeadConversion');

        $this->expectException(RuntimeException::class);

        $this->useCase->execute(new Guid(self::SUBMISSION_ID), true);
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
            attribution: new MarketingAttribution(gclid: $gclid, msclkid: $msclkid),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2026-05-15 09:00:00'),
                ipAddress: '127.0.0.1',
            ),
        );
    }
}
