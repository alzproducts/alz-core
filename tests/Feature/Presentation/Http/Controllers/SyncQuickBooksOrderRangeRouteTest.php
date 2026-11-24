<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderRangeJob;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\SpyOrderSyncDispatcher;
use Tests\TestCase;

/**
 * Requests arrive in 2031, where no seeded order is placed.
 */
#[CoversNothing]
final class SyncQuickBooksOrderRangeRouteTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/orders/sync-range';

    private const string REALM = '9130000000000001';

    private const int REFERENCE = 890000001;

    private const array RANGE = ['from' => '2031-07-10', 'to' => '2031-07-15'];

    private SpyOrderSyncDispatcher $dispatcher;

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        \config(['quickbooks.backfill_floor_date' => '2031-06-01']);
        $this->app->instance(OrderSyncDispatcherInterface::class, $this->dispatcher = new SpyOrderSyncDispatcher());
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        $this->arriveAt('2031-07-20 12:00:00+00');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function forceRetries(): iterable
    {
        yield 'absent' => [[], false];
        yield 'false' => [['force_retry' => false], false];
        yield 'true' => [['force_retry' => true], true];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('forceRetries')]
    public function a_range_with_orders_is_accepted_and_dispatched_once(array $body, bool $forceRetry): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $response = $this->sync([...self::RANGE, ...$body])->assertStatus(202);

        $runId = $response->json('run_id');
        self::assertIsString($runId);
        self::assertTrue(Str::isUuid($runId));
        $response->assertExactJson(['message' => 'QuickBooks order sync queued.', 'run_id' => $runId, 'candidate_count' => 1]);
        $this->assertDispatched(1);
        $run = $this->dispatcher->ranges[0];
        self::assertSame(
            [self::REALM, '2031-07-10', '2031-07-15', $forceRetry, $runId],
            [$run->realmId, $run->from->format('Y-m-d'), $run->to->format('Y-m-d'), $run->forceRetry, $run->runId],
        );
    }

    #[Test]
    public function candidates_are_counted_by_london_day_in_summer(): void
    {
        $this->connect();
        foreach (['2031-07-14 22:59:59+00', '2031-07-14 23:00:00+00', '2031-07-14 23:30:00+00', '2031-07-15 22:59:59+00', '2031-07-15 23:00:00+00'] as $i => $placedAt) {
            $this->insertOrder(self::REFERENCE + $i, $placedAt);
        }
        $this->insertSync(self::REFERENCE + 2);

        $this->sync(['from' => '2031-07-15', 'to' => '2031-07-15'])->assertStatus(202)->assertJsonPath('candidate_count', 3);
        $this->assertDispatched(1);
    }

    #[Test]
    public function an_empty_range_is_accepted_with_nothing_dispatched(): void
    {
        $this->connect();

        $this->sync(self::RANGE)->assertStatus(202)
            ->assertExactJson(['message' => 'QuickBooks order sync queued.', 'run_id' => null, 'candidate_count' => 0]);
        $this->assertDispatched(0);
    }

    #[Test]
    public function identical_requests_are_separate_runs(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $first = $this->sync(self::RANGE)->assertStatus(202)->json('run_id');
        $second = $this->sync(self::RANGE)->assertStatus(202)->json('run_id');

        self::assertNotSame($first, $second);
        self::assertSame([$first, $second], \array_column($this->dispatcher->ranges, 'runId'));
    }

    #[Test]
    public function each_accepted_request_queues_its_own_range_job(): void
    {
        Queue::fake();
        $this->app->forgetInstance(OrderSyncDispatcherInterface::class);
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $first = $this->sync([...self::RANGE, 'force_retry' => true])->assertStatus(202)->json('run_id');
        Queue::assertCount(1);
        $second = $this->sync([...self::RANGE, 'force_retry' => true])->assertStatus(202)->json('run_id');

        Queue::assertCount(2);
        $jobs = Queue::pushed(SyncQuickBooksOrderRangeJob::class, static fn(SyncQuickBooksOrderRangeJob $job, ?string $queue): bool => $queue === 'low')->values();
        self::assertNotSame($first, $second);
        self::assertSame([$first, $second], $jobs->map(static fn(SyncQuickBooksOrderRangeJob $job): string => $job->run->runId)->all());
        foreach ($jobs as $job) {
            self::assertSame(
                [self::REALM, '2031-07-10', '2031-07-15', true],
                [$job->run->realmId, $job->run->from->format('Y-m-d'), $job->run->to->format('Y-m-d'), $job->run->forceRetry],
            );
        }
    }

    #[Test]
    public function an_invalid_concurrency_fails_the_request_before_anything_is_queued(): void
    {
        Queue::fake();
        $this->app->forgetInstance(OrderSyncDispatcherInterface::class);
        \config(['quickbooks.order_sync_concurrency' => '0']);
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $this->sync(self::RANGE)->assertServerError();

        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'from missing' => [['to' => '2031-07-15'], 'from'];
        yield 'to missing' => [['from' => '2031-07-10'], 'to'];
        yield 'from not Y-m-d' => [['from' => '10/07/2031', 'to' => '2031-07-15'], 'from'];
        yield 'from not a calendar date' => [['from' => '2031-06-31', 'to' => '2031-07-15'], 'from'];
        yield 'to not Y-m-d' => [['from' => '2031-07-10', 'to' => '2031-07-15T00:00:00Z'], 'to'];
        yield 'to not a calendar date' => [['from' => '2031-07-10', 'to' => '2031-02-30'], 'to'];
        yield 'force_retry not boolean' => [[...self::RANGE, 'force_retry' => 'maybe'], 'force_retry'];
        yield 'to before from' => [['from' => '2031-07-10', 'to' => '2031-07-09'], 'to'];
        yield 'to after today' => [['from' => '2031-07-10', 'to' => '2031-07-21'], 'to'];
        yield '32 days' => [['from' => '2031-06-10', 'to' => '2031-07-11'], 'to'];
        yield 'from before the floor' => [['from' => '2031-05-31', 'to' => '2031-06-02'], 'from'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function an_invalid_body_is_refused_under_its_key(array $body, string $key): void
    {
        $this->connect();

        $this->assertRefused($this->sync($body), $key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function datedBodies(): iterable
    {
        yield 'force_retry absent' => [self::RANGE];
        yield 'force_retry false' => [[...self::RANGE, 'force_retry' => false]];
        yield 'force_retry true' => [[...self::RANGE, 'force_retry' => true]];
        yield 'past another limit too' => [['from' => '2031-07-10', 'to' => '2031-07-09', 'force_retry' => false]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('datedBodies')]
    public function every_dated_request_is_refused_while_the_floor_is_unset(array $body): void
    {
        $this->connect();
        \config(['quickbooks.backfill_floor_date' => null]);

        $this->assertRefused($this->sync($body), 'from');
    }

    #[Test]
    public function the_floor_date_is_sent_only_under_the_from_key(): void
    {
        $this->connect();

        $response = $this->sync(['from' => '2031-05-31', 'to' => '2031-06-02']);

        $this->assertRefused($response, 'from');
        $response->assertJsonPath('error.errors.from', ['The from date must not be before 2031-06-01.']);
        self::assertSame(1, \mb_substr_count((string) $response->getContent(), '2031-06-01'));
    }

    #[Test]
    public function a_malformed_date_keeps_its_key_while_the_floor_is_unset(): void
    {
        $this->connect();
        \config(['quickbooks.backfill_floor_date' => null]);

        $this->assertRefused($this->sync(['from' => '2031-07-10', 'to' => 'nope']), 'to');
    }

    #[Test]
    public function today_is_the_london_date_the_request_arrived_on(): void
    {
        $this->connect();
        $this->arriveAt('2031-07-14 23:30:00+00');

        $this->sync(self::RANGE)->assertStatus(202);
        $this->assertRefused($this->sync([...self::RANGE, 'to' => '2031-07-16']), 'to');
    }

    #[Test]
    public function an_unresolved_mapping_is_a_precondition_failure_before_any_quickbooks_call(): void
    {
        $this->saveConnection();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');
        $key = DB::table('quickbooks.mappings')->value('key');
        DB::table('quickbooks.mappings')->where('key', $key)->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);
        Http::fake(['*intuit.com*' => Http::response()]);

        $this->sync(self::RANGE)->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonPath('error.errors.missing_mapping_keys', [$key]);
        Http::assertNotSent(static fn(Request $request): bool => \str_contains($request->url(), 'intuit.com'));
        $this->assertDispatched(0);
    }

    #[Test]
    public function no_connection_is_a_precondition_failure(): void
    {
        $this->cacheToken();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $this->assertNotConnected($this->sync(self::RANGE));
    }

    #[Test]
    public function a_revoked_grant_is_a_precondition_failure(): void
    {
        $this->saveConnection();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNotConnected($this->sync(self::RANGE));
    }

    #[Test]
    public function a_transient_token_failure_is_unavailable(): void
    {
        $this->saveConnection();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([], 503)]);

        $this->sync(self::RANGE)->assertStatus(503);
        $this->assertDispatched(0);
    }

    #[Test]
    public function non_staff_are_refused(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2031-07-12 10:00:00+00');

        $this->asRole('guest')->postJson(self::URL, self::RANGE)->assertForbidden();
        $this->assertDispatched(0);
    }

    private function arriveAt(string $instant): void
    {
        $this->withServerVariables(['REQUEST_TIME_FLOAT' => (float) new CarbonImmutable($instant)->getTimestamp()]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<Response>
     */
    private function sync(array $body): TestResponse
    {
        return $this->asRole('standard')->postJson(self::URL, $body);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertRefused(TestResponse $response, string $key): void
    {
        $response->assertUnprocessable()->assertJsonPath('error.type', 'validation_error');
        $errors = $response->json('error.errors');
        self::assertIsArray($errors);
        self::assertSame([$key], \array_keys($errors));
        $this->assertDispatched(0);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertNotConnected(TestResponse $response): void
    {
        $response->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonMissingPath('error.errors');
        $this->assertDispatched(0);
    }

    private function assertDispatched(int $ranges): void
    {
        self::assertCount($ranges, $this->dispatcher->ranges);
        self::assertSame([], $this->dispatcher->chunks);
        self::assertSame([], $this->posting->calls);
    }

    private function connect(): void
    {
        $this->saveConnection();
        $this->cacheToken();
    }

    private function saveConnection(): void
    {
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('test-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
    }

    private function cacheToken(): void
    {
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }

    private function insertSync(int $reference): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'status' => 'failed',
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
        ]);
    }
}
