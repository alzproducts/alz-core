<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingNumberRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Conversion\CallTracking\Commands\AssignTrackingNumberCommand;
use App\Application\Conversion\CallTracking\UseCases\AssignTrackingNumberUseCase;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\ValueObjects\IpAddress;
use App\Domain\ValueObjects\Uuid;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(AssignTrackingNumberUseCase::class)]
final class AssignTrackingNumberUseCaseTest extends TestCase
{
    private const string DEFAULT_NUMBER = '+441234567000';

    private const string POOL_NUMBER_A = '+441234567001';

    private const string POOL_NUMBER_B = '+441234567002';

    private const string POOL_NUMBER_C = '+441234567003';

    private const string EXISTING_VISIT_ID = '11111111-2222-3333-4444-555555555555';

    private const string NEW_VISIT_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private CallTrackingVisitRepositoryInterface&MockInterface $visitRepository;

    private CallTrackingNumberRepositoryInterface&MockInterface $numberRepository;

    private DatabaseGatewayInterface&MockInterface $dbGateway;

    private LoggerInterface&MockInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->visitRepository = Mockery::mock(CallTrackingVisitRepositoryInterface::class);
        $this->numberRepository = Mockery::mock(CallTrackingNumberRepositoryInterface::class);
        $this->dbGateway = Mockery::mock(DatabaseGatewayInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    }

    #[Test]
    public function consent_denied_returns_default_number_with_null_visit_id_and_no_repository_calls(): void
    {
        $this->numberRepository->shouldNotReceive('findAllActive');
        $this->numberRepository->shouldNotReceive('incrementAndGetCounter');
        $this->visitRepository->shouldNotReceive('save');
        $this->visitRepository->shouldNotReceive('findRecentByClickId');

        $result = $this->buildUseCase()->execute($this->makeCommand(
            attribution: new MarketingAttribution(gclid: 'CjwKany'),
            marketingConsentGranted: false,
            userAgent: 'Mozilla/5.0',
        ));

        self::assertSame(self::DEFAULT_NUMBER, $result->phoneNumber->value);
        self::assertNull($result->callVisitId);
    }

    #[Test]
    public function dedup_hit_returns_existing_number_and_id_without_rotation_or_save(): void
    {
        $existing = $this->makeExistingVisit(self::POOL_NUMBER_B, self::EXISTING_VISIT_ID);

        $this->visitRepository->expects('findRecentByClickId')
            ->with('CjwKany', Mockery::type(DateTimeImmutable::class))
            ->andReturn($existing);

        $this->numberRepository->shouldNotReceive('findAllActive');
        $this->numberRepository->shouldNotReceive('incrementAndGetCounter');
        $this->visitRepository->shouldNotReceive('save');

        $result = $this->buildUseCase()->execute($this->makeCommand(
            attribution: new MarketingAttribution(gclid: 'CjwKany'),
        ));

        self::assertSame(self::POOL_NUMBER_B, $result->phoneNumber->value);
        self::assertNotNull($result->callVisitId);
        self::assertSame(self::EXISTING_VISIT_ID, $result->callVisitId->value);
    }

    #[Test]
    public function dedup_falls_through_to_msclkid_when_gclid_is_absent(): void
    {
        $existing = $this->makeExistingVisit(self::POOL_NUMBER_A, self::EXISTING_VISIT_ID);

        $this->visitRepository->expects('findRecentByClickId')
            ->with('msclk-xyz', Mockery::type(DateTimeImmutable::class))
            ->andReturn($existing);

        $result = $this->buildUseCase()->execute($this->makeCommand(
            attribution: new MarketingAttribution(msclkid: 'msclk-xyz'),
        ));

        self::assertSame(self::POOL_NUMBER_A, $result->phoneNumber->value);
        self::assertSame(self::EXISTING_VISIT_ID, $result->callVisitId?->value);
    }

    #[Test]
    public function no_click_id_with_active_pool_rotates_saves_and_returns_new_uuid(): void
    {
        $this->visitRepository->shouldNotReceive('findRecentByClickId');

        $this->numberRepository->expects('findAllActive')
            ->andReturn([
                PhoneNumberE164::from(self::POOL_NUMBER_A),
                PhoneNumberE164::from(self::POOL_NUMBER_B),
                PhoneNumberE164::from(self::POOL_NUMBER_C),
            ]);

        $this->expectTransactPassthrough();

        $this->numberRepository->expects('incrementAndGetCounter')->andReturn(1);

        $this->visitRepository->expects('save')
            ->with(Mockery::on(static fn(CallTrackingVisit $v): bool => $v->trackingNumberShown->value === self::POOL_NUMBER_B
                && $v->marketingConsentGranted === true
                && $v->ipAddress->value === '203.0.113.50'
                && $v->id === null
                && $v->userAgent === 'Mozilla/5.0'))
            ->andReturn(Uuid::fromTrusted(self::NEW_VISIT_ID));

        $result = $this->buildUseCase()->execute($this->makeCommand(
            userAgent: 'Mozilla/5.0',
        ));

        self::assertSame(self::POOL_NUMBER_B, $result->phoneNumber->value);
        self::assertSame(self::NEW_VISIT_ID, $result->callVisitId?->value);
    }

    #[Test]
    public function click_id_with_no_dedup_match_rotates_and_saves(): void
    {
        $this->visitRepository->expects('findRecentByClickId')
            ->with('CjwK-fresh', Mockery::type(DateTimeImmutable::class))
            ->andReturnNull();

        $this->numberRepository->expects('findAllActive')
            ->andReturn([
                PhoneNumberE164::from(self::POOL_NUMBER_A),
                PhoneNumberE164::from(self::POOL_NUMBER_B),
            ]);

        $this->expectTransactPassthrough();

        $this->numberRepository->expects('incrementAndGetCounter')->andReturn(2);

        $this->visitRepository->expects('save')
            ->with(Mockery::on(static fn(CallTrackingVisit $v): bool => $v->trackingNumberShown->value === self::POOL_NUMBER_A
                && $v->attribution->gclid === 'CjwK-fresh'))
            ->andReturn(Uuid::fromTrusted(self::NEW_VISIT_ID));

        $result = $this->buildUseCase()->execute($this->makeCommand(
            attribution: new MarketingAttribution(gclid: 'CjwK-fresh'),
        ));

        self::assertSame(self::POOL_NUMBER_A, $result->phoneNumber->value);
        self::assertSame(self::NEW_VISIT_ID, $result->callVisitId?->value);
    }

    #[Test]
    public function rotation_modulo_picks_index_one_for_counter_seven_pool_three(): void
    {
        $this->numberRepository->expects('findAllActive')
            ->andReturn([
                PhoneNumberE164::from(self::POOL_NUMBER_A),
                PhoneNumberE164::from(self::POOL_NUMBER_B),
                PhoneNumberE164::from(self::POOL_NUMBER_C),
            ]);

        $this->expectTransactPassthrough();

        $this->numberRepository->expects('incrementAndGetCounter')->andReturn(7);

        $this->visitRepository->expects('save')
            ->andReturn(Uuid::fromTrusted(self::NEW_VISIT_ID));

        $result = $this->buildUseCase()->execute($this->makeCommand());

        self::assertSame(self::POOL_NUMBER_B, $result->phoneNumber->value);
    }

    #[Test]
    public function empty_pool_returns_validated_default_without_save_or_rotation(): void
    {
        $this->numberRepository->expects('findAllActive')->andReturn([]);

        $this->numberRepository->shouldNotReceive('incrementAndGetCounter');
        $this->visitRepository->shouldNotReceive('save');
        $this->dbGateway->shouldNotReceive('transact');

        $result = $this->buildUseCase()->execute($this->makeCommand());

        self::assertSame(self::DEFAULT_NUMBER, $result->phoneNumber->value);
        self::assertNull($result->callVisitId);
    }

    private function buildUseCase(int $attributionWindowHours = 6): AssignTrackingNumberUseCase
    {
        return new AssignTrackingNumberUseCase(
            $this->visitRepository,
            $this->numberRepository,
            $this->dbGateway,
            $this->logger,
            PhoneNumberE164::from(self::DEFAULT_NUMBER),
            $attributionWindowHours,
        );
    }

    private function expectTransactPassthrough(): void
    {
        $this->dbGateway->expects('transact')
            ->andReturnUsing(static fn(Closure $op): mixed => $op());
    }

    private function makeCommand(
        ?MarketingAttribution $attribution = null,
        bool $marketingConsentGranted = true,
        ?string $userAgent = null,
    ): AssignTrackingNumberCommand {
        return new AssignTrackingNumberCommand(
            attribution: $attribution ?? MarketingAttribution::empty(),
            marketingConsentGranted: $marketingConsentGranted,
            ipAddress: IpAddress::from('203.0.113.50'),
            userAgent: $userAgent,
        );
    }

    private function makeExistingVisit(string $phone, string $id): CallTrackingVisit
    {
        return new CallTrackingVisit(
            attribution: MarketingAttribution::empty(),
            marketingConsentGranted: true,
            trackingNumberShown: PhoneNumberE164::from($phone),
            ipAddress: IpAddress::from('203.0.113.99'),
            id: Uuid::fromTrusted($id),
        );
    }
}
