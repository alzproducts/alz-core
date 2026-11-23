<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversNothing]
final class SyncQuickBooksOrdersRouteTest extends TestCase
{
    use DatabaseTransactions;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/orders/sync';

    private const string REALM = '9130000000000001';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private const array PORT_METHODS = ['findItems', 'findDocuments', 'findOrCreateCustomers', 'createDocuments'];

    /** @var list<string> */
    private array $portCalls = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        \config(['quickbooks.backfill_floor_date' => '2026-07-01']);
        $this->fakePosting();
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function invalidReferences(): iterable
    {
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

        $response = $this->sync(['references' => $references])->assertUnprocessable();

        $errors = $response->json('error.errors');
        self::assertIsArray($errors);
        self::assertSame($keys, \array_keys($errors));
        $this->assertNothingSynced();
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
    public function staff_are_allowed(): void
    {
        $this->connect();

        $this->sync(['references' => [self::REFERENCE]])
            ->assertOk()
            ->assertExactJson([
                'total' => 1,
                'counts' => ['synced' => 0, 'already_synced' => 0, 'skipped' => 1, 'failed' => 0, 'not_attempted' => 0],
                'results' => [[
                    'reference' => self::REFERENCE,
                    'outcome' => 'skipped',
                    'doc_type' => null,
                    'qbo_doc_number' => null,
                    'qbo_txn_id' => null,
                    'warnings' => [],
                    'reason' => ['code' => 'order_not_found', 'message' => 'No order has this reference.', 'retryable' => false],
                ]],
            ]);
        $this->assertNothingSynced();
    }

    #[Test]
    public function non_staff_are_refused(): void
    {
        $this->connect();

        $this->asRole('guest')->postJson(self::URL, ['references' => [self::REFERENCE]])->assertForbidden();
        $this->assertNothingSynced();
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
        $this->assertNothingSynced();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function forceRetries(): iterable
    {
        yield 'without force_retry' => [false];
        yield 'with force_retry' => [true];
    }

    #[Test]
    #[DataProvider('forceRetries')]
    public function a_synced_row_is_already_synced(bool $forceRetry): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-1', 'qbo_txn_id' => 'txn-1']);

        $this->sync(['references' => [self::REFERENCE], 'force_retry' => $forceRetry])
            ->assertOk()
            ->assertJsonPath('counts.already_synced', 1)
            ->assertJsonPath('results', [[
                'reference' => self::REFERENCE,
                'outcome' => 'already_synced',
                'doc_type' => 'invoice',
                'qbo_doc_number' => 'DOC-1',
                'qbo_txn_id' => 'txn-1',
                'warnings' => [],
                'reason' => null,
            ]]);
        self::assertSame([], $this->portCalls);
    }

    #[Test]
    public function a_failed_row_is_skipped_without_force_retry(): void
    {
        $this->connect();
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing']);
        $before = DB::table('quickbooks.order_syncs')->get()->toArray();

        $this->sync(['references' => [self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason.code', 'previously_failed');
        self::assertEquals($before, DB::table('quickbooks.order_syncs')->get()->toArray());
        self::assertSame([], $this->portCalls);
    }

    #[Test]
    public function results_keep_the_request_order(): void
    {
        $this->connect();

        $this->sync(['references' => [self::OTHER_REFERENCE, self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('results.0.reference', self::OTHER_REFERENCE)
            ->assertJsonPath('results.1.reference', self::REFERENCE);
    }

    #[Test]
    public function a_transient_token_failure_is_unavailable(): void
    {
        $this->saveConnection();
        $this->insertOrder(self::REFERENCE);
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([], 503)]);

        $this->sync(['references' => [self::REFERENCE]])->assertStatus(503);
        $this->assertNothingSynced();
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
        $this->assertNothingSynced();
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertNotConnected(TestResponse $response): void
    {
        $response->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonMissingPath('error.errors');
        $this->assertNothingSynced();
    }

    private function assertNothingSynced(): void
    {
        self::assertSame(0, DB::table('quickbooks.order_syncs')->count());
        self::assertSame([], $this->portCalls);
    }

    private function fakePosting(): void
    {
        $posting = Mockery::mock(QuickBooksOrderPostingInterface::class);
        foreach (self::PORT_METHODS as $method) {
            $posting->shouldReceive($method)->andReturnUsing(function () use ($method): never {
                $this->portCalls[] = $method;

                self::fail("Unexpected QBO call: {$method}");
            });
        }
        $this->app->instance(QuickBooksOrderPostingInterface::class, $posting);
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

    /**
     * @param array<string, mixed> $state
     */
    private function insertSync(array $state): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => self::REFERENCE,
            'order_external_id' => self::REFERENCE,
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
            ...$state,
        ]);
    }

    private function insertOrder(int $reference, string $placedAt = '2026-11-01 10:00:00+00'): void
    {
        $orderId = DB::table('shopwired.orders')->insertGetId([
            'external_id' => $reference,
            'reference' => $reference,
            'total' => '29.990000',
            'sub_total_net' => '25.000000',
            'shipping_total_net' => '4.990000',
            'shipping_vat_rate' => '20.0',
            'shipping_method' => 'Next Day',
            'status_name' => 'Processing',
            'status_type' => 'processing',
            'lifecycle_status' => 'processing',
            'customer_id' => 1,
            'customer_type' => 1,
            'billing_name' => 'Test Buyer',
            'billing_email' => 'buyer@example.test',
            'billing_address_line1' => '1 Test Street',
            'billing_city' => 'Testville',
            'billing_postcode' => 'TE1 1ST',
            'billing_country' => 'United Kingdom',
            'delivery_name' => 'Test Buyer',
            'delivery_email' => 'buyer@example.test',
            'delivery_address_line1' => '1 Test Street',
            'delivery_city' => 'Testville',
            'delivery_postcode' => 'TE1 1ST',
            'delivery_country' => 'United Kingdom',
            'shipping_charge_net' => '4.990000',
            'payment_method' => 'card',
            'payment_method_raw' => 'Opayo Hosted',
            'order_placed_at' => $placedAt,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);
        DB::table('shopwired.order_products')->insert([
            'order_id' => $orderId,
            'order_external_id' => $reference,
            'external_id' => 10,
            'title' => 'Line',
            'sku' => 'SKU-10',
            'price' => '12.5',
            'price_vat' => '2.5',
            'total' => '25.0',
            'total_vat' => '5.0',
            'original_price' => '12.5',
            'quantity' => 2,
            'vat_rate' => '20',
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);
    }
}
