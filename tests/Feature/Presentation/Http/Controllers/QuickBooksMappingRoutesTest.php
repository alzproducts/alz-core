<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceSession;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use App\Presentation\Http\QuickBooks\QuickBooksMappingDeadlineFactory;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\DataService\DataService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversNothing]
final class QuickBooksMappingRoutesTest extends TestCase
{
    use DatabaseTransactions;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/mappings';

    private const string REALM = '9130000000000001';

    private const string OTHER_REALM = '9130000000000002';

    private const string USER_ID = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';

    private MockInterface&QuickBooksMappingCandidatesInterface $adapter;

    /** @var list<MessageLogged> */
    private array $logs = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        foreach (MappingKey::cases() as $key) {
            $this->store($key, self::refId($key), self::refName($key), self::REALM);
        }

        // intentional: resolved first, since a deferred provider loading later would drop the double below.
        $this->app->make(QuickBooksRealmInterface::class);
        $this->app->make(QuickBooksMappingRepositoryInterface::class);
        $this->app->make(QuickBooksMappingCandidatesInterface::class);

        $this->adapter = Mockery::mock(QuickBooksMappingCandidatesInterface::class);
        $this->app->instance(QuickBooksMappingCandidatesInterface::class, $this->adapter);

        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logs[] = $log;
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_list_returns_every_seeded_key_with_its_state_from_the_database_alone(): void
    {
        $this->saveConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([], 500)]);
        $this->adapter->shouldNotReceive('candidates');
        $this->store(MappingKey::TaxZero, null, null, null);
        $this->store(MappingKey::Department, 'dep-9', 'Old Dept', self::OTHER_REALM);
        DB::table('quickbooks.mappings')->where('key', MappingKey::TaxStandard->value)->update(['resolved_at' => '2026-11-01 09:30:00+00']);

        $response = $this->asRole('standard')->getJson(self::URL)->assertOk();

        $items = $this->byKey($response->json('data'));
        self::assertSame(\array_map(static fn(MappingKey $key): string => $key->value, MappingKey::cases()), \array_keys($items));
        self::assertSame([
            'key' => 'tax.standard',
            'entity' => 'TaxCode',
            'ref_id' => 'id-tax.standard',
            'ref_name' => 'Name tax.standard',
            'realm_id' => self::REALM,
            'resolved_at' => '2026-11-01T09:30:00+00:00',
            'state' => 'resolved',
        ], $items['tax.standard']);
        self::assertSame(['ref_id' => null, 'ref_name' => null, 'realm_id' => null, 'state' => 'unresolved'], self::pick($items['tax.zero'], 'ref_id', 'ref_name', 'realm_id', 'state'));
        self::assertSame(['ref_id' => 'dep-9', 'realm_id' => self::OTHER_REALM, 'state' => 'other_realm'], self::pick($items['department'], 'ref_id', 'realm_id', 'state'));
        Http::assertNotSent(static fn(Request $request): bool => $request->url() === QuickBooksConfig::TOKEN_URL);
    }

    #[Test]
    public function the_list_is_a_precondition_failure_when_a_credential_is_blank(): void
    {
        \config(['quickbooks.client_secret' => '']);
        $this->app->forgetInstance(QuickBooksConfig::class);

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL));
    }

    #[Test]
    public function candidates_are_the_active_entities_of_the_keys_entity_type(): void
    {
        $this->connect();
        $this->adapterReturns(MappingEntity::TaxCode, [
            new MappingCandidateDTO('7', 'Standard 20%', true, 'Sales 20%'),
            new MappingCandidateDTO('8', 'Old rate', false),
        ]);

        $response = $this->asRole('standard')->getJson(self::URL . '/tax.standard/candidates')->assertOk();

        self::assertSame([['id' => '7', 'name' => 'Standard 20%', 'detail' => 'Sales 20%', 'current' => false]], $response->json('data'));
    }

    #[Test]
    public function the_candidate_the_row_holds_for_the_configured_realm_is_current(): void
    {
        $this->connect();
        $this->store(MappingKey::TaxStandard, '7', 'Standard 20%', self::REALM);
        $this->adapterReturns(MappingEntity::TaxCode, [
            new MappingCandidateDTO('7', 'Standard 20%', true),
            new MappingCandidateDTO('9', 'Zero', true),
        ]);

        $response = $this->asRole('standard')->getJson(self::URL . '/tax.standard/candidates')->assertOk();

        self::assertSame(['7' => true, '9' => false], $this->currentFlags($response));
    }

    #[Test]
    public function no_candidate_is_current_when_the_row_holds_its_id_under_another_realm(): void
    {
        $this->connect();
        $this->store(MappingKey::TaxStandard, '7', 'Standard 20%', self::OTHER_REALM);
        $this->adapterReturns(MappingEntity::TaxCode, [
            new MappingCandidateDTO('7', 'Standard 20%', true),
            new MappingCandidateDTO('9', 'Zero', true),
        ]);

        $response = $this->asRole('standard')->getJson(self::URL . '/tax.standard/candidates')->assertOk();

        self::assertSame(['7' => false, '9' => false], $this->currentFlags($response));
    }

    #[Test]
    public function an_admin_pick_stores_the_candidates_quickbooks_name_and_the_configured_realm(): void
    {
        $this->connect();
        $this->store(MappingKey::TaxStandard, null, null, null);
        $this->adapterReturns(MappingEntity::TaxCode, [new MappingCandidateDTO('7', 'Standard 20%', true)]);

        $response = $this->asRole('manager')
            ->putJson(self::URL . '/tax.standard', ['ref_id' => '7', 'ref_name' => 'Client name'])
            ->assertOk();

        $row = $this->row(MappingKey::TaxStandard);
        self::assertSame(['7', 'Standard 20%', self::REALM], [$row->qbo_ref_id, $row->qbo_ref_name, $row->realm_id]);
        $listed = $this->byKey($this->asRole('standard')->getJson(self::URL)->json('data'))['tax.standard'];
        self::assertSame($listed, $response->json('data'));
        self::assertSame('resolved', $listed['state']);
    }

    #[Test]
    public function a_pick_that_is_not_an_active_candidate_is_refused_and_the_row_is_kept(): void
    {
        $this->connect();
        $this->adapterReturns(MappingEntity::TaxCode, [
            new MappingCandidateDTO('7', 'Standard 20%', true),
            new MappingCandidateDTO('8', 'Old rate', false),
        ]);
        $before = $this->row(MappingKey::TaxStandard);

        $response = $this->asRole('manager')->putJson(self::URL . '/tax.standard', ['ref_id' => '8'])->assertUnprocessable();

        $response->assertJsonPath('error.type', 'validation_error');
        $errors = $response->json('error.errors');
        self::assertIsArray($errors);
        self::assertSame(['ref_id'], \array_keys($errors));
        self::assertEquals($before, $this->row(MappingKey::TaxStandard));
    }

    #[Test]
    public function a_staff_member_below_admin_area_cannot_pick(): void
    {
        $this->connect();
        $this->adapter->shouldNotReceive('candidates');
        $before = $this->row(MappingKey::TaxStandard);

        $this->asRole('standard')->putJson(self::URL . '/tax.standard', ['ref_id' => '7'])->assertForbidden();

        self::assertEquals($before, $this->row(MappingKey::TaxStandard));
    }

    #[Test]
    public function the_check_returns_one_finding_per_drifted_key(): void
    {
        $this->connect();
        $this->store(MappingKey::TaxStandard, null, null, null);
        $this->store(MappingKey::TaxZero, 'id-tax.zero', 'Name tax.zero', self::OTHER_REALM);
        $this->adapter->shouldReceive('candidates')->andReturnUsing(
            static fn(ApiKeyToken $token, MappingEntity $entity): array => self::quickBooksHolding($entity),
        );

        $response = $this->asRole('standard')->getJson(self::URL . '/check')->assertOk();

        self::assertEquals([
            'tax.standard' => ['key' => 'tax.standard', 'kind' => 'unresolved', 'stored_name' => null, 'current_name' => null],
            'tax.zero' => ['key' => 'tax.zero', 'kind' => 'other_realm', 'stored_name' => 'Name tax.zero', 'current_name' => null],
            'deposit_account.paypal' => ['key' => 'deposit_account.paypal', 'kind' => 'name_drift', 'stored_name' => 'Name deposit_account.paypal', 'current_name' => 'Renamed in QuickBooks'],
            'payment_method.paypal' => ['key' => 'payment_method.paypal', 'kind' => 'inactive', 'stored_name' => 'Name payment_method.paypal', 'current_name' => 'Name payment_method.paypal'],
            'department' => ['key' => 'department', 'kind' => 'not_found', 'stored_name' => 'Name department', 'current_name' => null],
        ], $this->byKey($response->json('data')));
        $response->assertJsonCount(5, 'data');
    }

    #[Test]
    public function an_access_token_quickbooks_rejects_is_a_precondition_failure(): void
    {
        $this->connect();
        $this->adapter->shouldReceive('candidates')->once()->andThrow(new AuthenticationExpiredException('QuickBooks'));

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL . '/tax.standard/candidates'));
    }

    #[Test]
    public function a_rejected_refresh_is_a_precondition_failure(): void
    {
        $this->saveConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->adapter->shouldNotReceive('candidates');

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL . '/tax.standard/candidates'));
    }

    #[Test]
    public function an_unknown_key_is_not_found(): void
    {
        $this->adapter->shouldNotReceive('candidates');

        $this->asRole('standard')->getJson(self::URL . '/tax.unknown/candidates')->assertNotFound();
    }

    #[Test]
    public function a_pick_that_changes_the_row_logs_the_key_both_refs_and_the_user(): void
    {
        $this->connect();
        $this->adapterReturns(MappingEntity::TaxCode, [new MappingCandidateDTO('7', 'Standard 20%', true)]);

        $this->asRole('manager')->putJson(self::URL . '/tax.standard', ['ref_id' => '7'])->assertOk();

        $picks = $this->pickLogs();
        self::assertCount(1, $picks);
        self::assertSame('info', $picks[0]->level);
        self::assertSame('tax.standard', $picks[0]->context['key']);
        self::assertSame('id-tax.standard', $picks[0]->context['old_ref_id']);
        self::assertSame('7', $picks[0]->context['new_ref_id']);
        self::assertSame(self::USER_ID, $picks[0]->context['user_id']);
    }

    #[Test]
    public function a_pick_equal_to_the_row_writes_nothing_and_still_answers(): void
    {
        $this->connect();
        $this->store(MappingKey::TaxStandard, '7', 'Standard 20%', self::REALM);
        DB::table('quickbooks.mappings')->where('key', MappingKey::TaxStandard->value)->update(['resolved_at' => '2026-11-01 09:30:00+00']);
        $this->adapterReturns(MappingEntity::TaxCode, [new MappingCandidateDTO('7', 'Standard 20%', true)]);

        $response = $this->asRole('manager')->putJson(self::URL . '/tax.standard', ['ref_id' => '7'])->assertOk();

        self::assertSame('2026-11-01T09:30:00+00:00', $response->json('data.resolved_at'));
        self::assertSame('2026-11-01 09:30:00+00', $this->row(MappingKey::TaxStandard)->resolved_at);
        self::assertSame([], $this->pickLogs());
    }

    #[Test]
    public function a_stalled_check_is_unavailable_before_octane_kills_the_worker(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00');
        Sleep::fake(syncWithCarbon: true);
        $this->connect();
        $this->app->instance(QuickBooksMappingCandidatesInterface::class, $this->stallingReader(14));
        $start = CarbonImmutable::now();
        $this->withServerVariables(['REQUEST_TIME_FLOAT' => (float) $start->subSeconds(5)->getTimestamp()]);

        $response = $this->asRole('standard')->getJson(self::URL . '/check');

        $response->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)->assertJsonPath('error.type', 'service_unavailable');
        $elapsed = $start->diffInSeconds(CarbonImmutable::now());
        self::assertGreaterThanOrEqual(14, $elapsed);
        self::assertLessThanOrEqual(QuickBooksMappingDeadlineFactory::SECONDS - 5, $elapsed);
    }

    /**
     * Every key resolved to a matching active entity, except the three drifted keys the check must report.
     *
     * @return list<MappingCandidateDTO>
     */
    private static function quickBooksHolding(MappingEntity $entity): array
    {
        $candidates = [];
        foreach (MappingKey::cases() as $key) {
            if ($key->entity() !== $entity || $key === MappingKey::Department) {
                continue;
            }

            $candidates[] = match ($key) {
                MappingKey::DepositAccountPayPal => new MappingCandidateDTO(self::refId($key), 'Renamed in QuickBooks', true),
                MappingKey::PaymentMethodPayPal => new MappingCandidateDTO(self::refId($key), self::refName($key), false),
                default => new MappingCandidateDTO(self::refId($key), self::refName($key), true),
            };
        }

        return $candidates;
    }

    private function stallingReader(int $secondsPerQuery): QuickBooksMappingCandidateReader
    {
        $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
        $factory->shouldReceive('create')->andReturnUsing(static function () use ($secondsPerQuery): QuickBooksDataServiceSession {
            $dataService = Mockery::mock(DataService::class);
            $dataService->shouldReceive('Query')->andReturnUsing(static function () use ($secondsPerQuery): array {
                Carbon::setTestNow(Carbon::now()->addSeconds($secondsPerQuery));

                return [];
            });
            $dataService->shouldReceive('getLastError')->andReturn(false);

            return new QuickBooksDataServiceSession($dataService, new QuickBooksCurlHttpClient(15));
        });
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquire', 'acquireBy');
        $throttle = Mockery::mock(TransientLogThrottle::class);
        $throttle->shouldReceive('logTransient');

        return new QuickBooksMappingCandidateReader(new QuickBooksTransport($factory, $limiter, $throttle, new NullLogger()), new NullLogger());
    }

    /**
     * @param list<MappingCandidateDTO> $candidates
     */
    private function adapterReturns(MappingEntity $entity, array $candidates): void
    {
        $this->adapter->shouldReceive('candidates')
            ->once()
            ->with(Mockery::type(ApiKeyToken::class), $entity, Mockery::any())
            ->andReturn($candidates);
    }

    /**
     * @param TestResponse<Response> $response
     *
     * @return array<string, bool>
     */
    private function currentFlags(TestResponse $response): array
    {
        $data = $response->json('data');
        self::assertIsArray($data);

        return \array_column($data, 'current', 'id');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byKey(mixed $data): array
    {
        self::assertIsArray($data);

        return \array_column($data, null, 'key');
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function pick(array $item, string ...$fields): array
    {
        return \array_intersect_key($item, \array_flip($fields));
    }

    /**
     * @return list<MessageLogged>
     */
    private function pickLogs(): array
    {
        return \array_values(\array_filter(
            $this->logs,
            static fn(MessageLogged $log): bool => $log->message === 'Recorded QuickBooks mapping pick',
        ));
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertNotConnected(TestResponse $response): void
    {
        $response->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonMissingPath('error.errors');
    }

    private function store(MappingKey $key, ?string $refId, ?string $refName, ?string $realmId): void
    {
        DB::table('quickbooks.mappings')->where('key', $key->value)->update([
            'qbo_ref_id' => $refId,
            'qbo_ref_name' => $refName,
            'realm_id' => $realmId,
        ]);
    }

    private function row(MappingKey $key): object
    {
        $row = DB::table('quickbooks.mappings')->where('key', $key->value)->first();
        if ($row === null) {
            throw new RuntimeException("No seeded row for {$key->value}");
        }

        return $row;
    }

    private static function refId(MappingKey $key): string
    {
        return "id-{$key->value}";
    }

    private static function refName(MappingKey $key): string
    {
        return "Name {$key->value}";
    }

    private function connect(): void
    {
        $this->saveConnection();
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }

    private function saveConnection(): void
    {
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('test-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
    }
}
