<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Feature\Concerns\RunsQueuedJobs;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\FakeSlotRedis;
use Tests\TestCase;

#[CoversNothing]
final class SyncQuickBooksOrdersRouteTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;
    use RunsQueuedJobs;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/orders/sync';

    private const string REALM = '9130000000000001';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        \config(['quickbooks.backfill_floor_date' => '2026-07-01']);
        Queue::fake();
        $this->posting = new FakeQuickBooksOrderPosting();
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting);
    }

    /**
     * @return iterable<string, array{array<string, bool>, bool}>
     */
    public static function forceRetries(): iterable
    {
        yield 'absent' => [[], false];
        yield 'false' => [['force_retry' => false], false];
        yield 'true' => [['force_retry' => true], true];
    }

    /**
     * @param array<string, bool> $extra
     */
    #[Test]
    #[DataProvider('forceRetries')]
    public function staff_are_allowed(array $extra, bool $forceRetry): void
    {
        $this->connect();
        $references = \range(self::REFERENCE, self::REFERENCE + 9);
        $this->insertOrder(self::REFERENCE);

        $response = $this->sync(['references' => $references, ...$extra])->assertStatus(202);

        $runId = $response->json('run_id');
        self::assertIsString($runId);
        self::assertTrue(Str::isUuid($runId));
        $response->assertExactJson(['message' => 'QuickBooks order sync queued.', 'run_id' => $runId]);
        $jobs = $this->queuedJobs(1);
        self::assertEquals(
            new OrderChunkRunDTO(new OrderSyncChunkDTO(self::REALM, $references, $forceRetry, OrderSyncTrigger::Manual, $runId)),
            $jobs[0]->run,
        );
        self::assertSame('low', $jobs[0]->queue);
        $this->assertNothingSynced();
    }

    /**
     * @param array<string, bool> $extra
     */
    #[Test]
    #[DataProvider('forceRetries')]
    public function the_queued_job_syncs_its_chunk_as_a_manual_run(array $extra, bool $forceRetry): void
    {
        $this->connect();
        $this->app->instance(QuickBooksOrderChunkSlot::class, new QuickBooksOrderChunkSlot(new FakeSlotRedis(), new NullLogger(), 1));
        $synced = [];
        $useCase = $this->createStub(SyncOrderChunkUseCase::class);
        $useCase->method('execute')->willReturnCallback(
            static function (ApiKeyToken $token, OrderChunkRunDTO $run) use (&$synced): OrderSyncResultsResult {
                $synced[] = $run->chunk;

                return OrderSyncResultsResult::inRequestOrder(
                    $run->chunk->references,
                    [OrderSyncResultDTO::refused(self::REFERENCE, SyncReasonCode::OrderNotFound)],
                );
            },
        );
        $this->app->instance(SyncOrderChunkUseCase::class, $useCase);

        $runId = $this->sync(['references' => [self::REFERENCE], ...$extra])->assertStatus(202)->json('run_id');
        $job = $this->work($this->queuedJobs(1)[0]);

        self::assertFalse($job->hasFailed());
        self::assertCount(1, $synced);
        self::assertSame(
            [[self::REFERENCE], OrderSyncTrigger::Manual, $forceRetry, $runId],
            [$synced[0]->references, $synced[0]->trigger, $synced[0]->forceRetry, $synced[0]->runId],
        );
    }

    #[Test]
    public function identical_requests_are_separate_runs(): void
    {
        $this->connect();

        $first = $this->sync(['references' => [self::REFERENCE]])->assertStatus(202)->json('run_id');
        $second = $this->sync(['references' => [self::REFERENCE]])->assertStatus(202)->json('run_id');

        self::assertNotSame($first, $second);
        self::assertSame(
            [$first, $second],
            \array_map(static fn(SyncQuickBooksOrderChunkJob $job): ?string => $job->run->chunk->runId, $this->queuedJobs(2)),
        );
    }

    #[Test]
    public function one_log_entry_holds_the_run_id_and_its_references(): void
    {
        $this->connect();
        $entries = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $log) use (&$entries): void {
            if (isset($log->context['run_id'], $log->context['references'])) {
                $entries[] = [$log->level, $log->context['run_id'], $log->context['references']];
            }
        });

        $runId = $this->sync(['references' => [self::OTHER_REFERENCE, self::REFERENCE]])->assertStatus(202)->json('run_id');

        self::assertSame([['info', $runId, [self::OTHER_REFERENCE, self::REFERENCE]]], $entries);
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'missing' => [null, ['references']];
        yield 'empty list' => [[], ['references']];
        yield '11 references' => [\range(1, 11), ['references']];
        yield 'non-integer' => [[1, 'abc'], ['references.1']];
        yield 'numeric string' => [['12'], ['references.0']];
        yield 'above the integer column' => [[2147483648], ['references.0']];
        yield 'zero' => [[0], ['references.0']];
        yield 'duplicate' => [[5, 6, 5], ['references.0', 'references.2']];
    }

    /**
     * @param list<string> $keys
     */
    #[Test]
    #[DataProvider('invalidReferences')]
    public function an_invalid_body_is_refused_under_its_key(mixed $references, array $keys): void
    {
        $this->connect();

        $response = $this->sync($references === null ? [] : ['references' => $references])->assertUnprocessable();

        $errors = $response->json('error.errors');
        self::assertIsArray($errors);
        self::assertSame($keys, \array_keys($errors));
        $this->assertNothingQueued();
    }

    #[Test]
    public function one_order_before_london_midnight_on_the_floor_date_refuses_the_request(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2026-06-30 22:59:59+00');
        $this->insertOrder(self::OTHER_REFERENCE, '2026-07-02 10:00:00+00');

        $this->assertReferencesRefused($this->sync(['references' => [self::REFERENCE, self::OTHER_REFERENCE]]));
    }

    #[Test]
    public function an_order_placed_exactly_at_the_floor_is_synced(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE, '2026-06-30 23:00:00+00');

        $this->sync(['references' => [self::REFERENCE]])->assertStatus(202);
        self::assertSame([self::REFERENCE], $this->queuedJobs(1)[0]->run->chunk->references);
    }

    #[Test]
    public function a_postable_order_is_refused_while_the_floor_is_unset(): void
    {
        $this->connect();
        \config(['quickbooks.backfill_floor_date' => null]);
        $this->insertOrder(self::REFERENCE);

        $this->assertReferencesRefused($this->sync(['references' => [self::REFERENCE]]));
    }

    #[Test]
    public function unknown_references_are_refused_while_the_floor_is_unset(): void
    {
        $this->connect();
        \config(['quickbooks.backfill_floor_date' => null]);

        $this->assertReferencesRefused($this->sync(['references' => [self::REFERENCE, self::OTHER_REFERENCE]]));
    }

    #[Test]
    public function non_staff_are_refused(): void
    {
        $this->connect();

        $this->asRole('guest')->postJson(self::URL, ['references' => [self::REFERENCE]])->assertForbidden();
        $this->assertNothingQueued();
    }

    #[Test]
    public function a_cached_token_without_a_connection_is_a_precondition_failure(): void
    {
        $this->cacheToken();

        $this->assertNotConnected($this->sync(['references' => [self::REFERENCE]]));
    }

    #[Test]
    public function no_connection_with_unresolved_mappings_is_still_the_connection_failure(): void
    {
        $this->cacheToken();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);

        $this->assertNotConnected($this->sync(['references' => [self::REFERENCE]]));
    }

    #[Test]
    public function a_refused_refresh_is_a_precondition_failure(): void
    {
        $this->saveConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNotConnected($this->sync(['references' => [self::REFERENCE]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function connectionKeys(): iterable
    {
        yield 'client id' => ['client_id'];
        yield 'client secret' => ['client_secret'];
        yield 'redirect uri' => ['redirect_uri'];
        yield 'realm id' => ['realm_id'];
    }

    #[Test]
    #[DataProvider('connectionKeys')]
    public function a_blank_connection_key_is_a_precondition_failure(string $key): void
    {
        $this->connect();
        \config(["quickbooks.{$key}" => '']);
        $this->app->forgetInstance(QuickBooksConfig::class);

        $this->assertNotConnected($this->sync(['references' => [self::REFERENCE]]));
    }

    #[Test]
    public function an_invalid_environment_is_a_server_error(): void
    {
        $this->connect();
        \config(['quickbooks.environment' => 'neither']);
        $this->app->forgetInstance(QuickBooksConfig::class);

        $this->sync(['references' => [self::REFERENCE]])->assertStatus(500);
        $this->assertNothingQueued();
    }

    #[Test]
    public function an_unresolved_mapping_key_is_a_precondition_failure_naming_it(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE);
        $key = DB::table('quickbooks.mappings')->orderBy('key')->value('key');
        self::assertIsString($key);
        DB::table('quickbooks.mappings')->where('key', $key)->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);

        $this->sync(['references' => [self::REFERENCE]])
            ->assertStatus(412)
            ->assertJsonPath('error.errors.missing_mapping_keys', [$key]);
        $this->assertNothingQueued();
    }

    #[Test]
    public function a_transient_token_failure_is_unavailable(): void
    {
        $this->saveConnection();
        $this->insertOrder(self::REFERENCE);
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([], 503)]);

        $this->sync(['references' => [self::REFERENCE]])->assertStatus(503);
        $this->assertNothingQueued();
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
    private function assertReferencesRefused(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('error.type', 'validation_error');
        $errors = $response->json('error.errors');
        self::assertIsArray($errors);
        self::assertSame(['references'], \array_keys($errors));
        $this->assertNothingQueued();
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertNotConnected(TestResponse $response): void
    {
        $response->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonMissingPath('error.errors');
        $this->assertNothingQueued();
    }

    /**
     * @return list<SyncQuickBooksOrderChunkJob>
     */
    private function queuedJobs(int $count): array
    {
        Queue::assertCount($count);

        return \array_values(Queue::pushed(SyncQuickBooksOrderChunkJob::class)->all());
    }

    private function assertNothingQueued(): void
    {
        Queue::assertNothingPushed();
        $this->assertNothingSynced();
    }

    private function assertNothingSynced(): void
    {
        self::assertSame(0, DB::table('quickbooks.order_syncs')->count());
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
}
