<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversNothing]
final class ListFailedQuickBooksOrdersRouteTest extends TestCase
{
    use DatabaseTransactions;
    use SendsRoleTokens;

    private const string URL = '/api/quickbooks/orders/failed';

    private const string REALM = '9130000000000001';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSupabaseJwks();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        DB::table('quickbooks.order_syncs')->delete();
    }

    #[Test]
    public function staff_get_the_failed_rows_of_this_company_newest_attempt_first(): void
    {
        $this->connect();
        $this->insertOrder(890000001, 890000001);
        $this->insertSync(890000001, [
            'doc_type' => 'invoice',
            'attempts' => 2,
            'last_attempted_at' => '2026-11-02 10:00:00+00',
            'last_error_code' => 'item_missing',
            'last_error_message' => 'SKU TEST-1 has no QuickBooks item.',
        ]);
        $this->insertSync(890000002, [
            'attempts' => 1,
            'last_attempted_at' => '2026-11-03 10:00:00+00',
            'last_error_code' => 'zero_lines',
        ]);
        $this->insertSync(890000003, ['realm_id' => 'another-company']);
        $this->insertSync(890000004, ['status' => 'synced']);

        $response = $this->asRole('standard')->getJson(self::URL);

        $response->assertOk();
        self::assertSame([
            [
                'reference' => 890000002,
                'order_external_id' => 890000002,
                'order_placed_at' => null,
                'doc_type' => null,
                'status' => 'failed',
                'attempts' => 1,
                'last_attempted_at' => '2026-11-03T10:00:00+00:00',
                'reason' => [
                    'code' => 'zero_lines',
                    'message' => 'The order produced no document lines.',
                    'retryable' => false,
                ],
                'warnings' => [],
            ],
            [
                'reference' => 890000001,
                'order_external_id' => 890000001,
                'order_placed_at' => '2026-11-01T10:00:00+00:00',
                'doc_type' => 'invoice',
                'status' => 'failed',
                'attempts' => 2,
                'last_attempted_at' => '2026-11-02T10:00:00+00:00',
                'reason' => [
                    'code' => 'item_missing',
                    'message' => 'SKU TEST-1 has no QuickBooks item.',
                    'retryable' => true,
                ],
                'warnings' => [],
            ],
        ], $response->json('data'));
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 50);
    }

    #[Test]
    public function pages_carry_the_query_parameters(): void
    {
        $this->connect();
        $this->insertSync(890000001);
        $this->insertSync(890000002);

        $response = $this->asRole('standard')->getJson(self::URL . '?status=failed&per_page=1&page=2');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
        $previous = $response->json('links.prev');
        self::assertIsString($previous);
        self::assertStringContainsString('status=failed', $previous);
        self::assertStringContainsString('per_page=1', $previous);
    }

    #[Test]
    public function changed_after_sync_is_an_empty_page(): void
    {
        $this->connect();
        $this->insertSync(890000001);

        $this->asRole('standard')->getJson(self::URL . '?status=changed_after_sync')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function per_page_accepts_its_maximum(): void
    {
        $this->connect();

        $this->asRole('standard')->getJson(self::URL . '?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1000);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'per_page above the maximum' => ['per_page=1001', 'per_page'];
        yield 'per_page zero' => ['per_page=0', 'per_page'];
        yield 'page zero' => ['page=0', 'page'];
        yield 'another row status' => ['status=synced', 'status'];
    }

    #[Test]
    #[DataProvider('invalidQueries')]
    public function an_invalid_query_is_refused_under_its_field(string $query, string $field): void
    {
        $this->connect();

        $response = $this->asRole('standard')->getJson(self::URL . '?' . $query)->assertUnprocessable();

        self::assertIsArray($response->json("error.errors.{$field}"));
    }

    #[Test]
    public function non_staff_are_refused(): void
    {
        $this->asRole('guest')->getJson(self::URL)->assertForbidden();
    }

    #[Test]
    public function a_cached_token_without_a_connection_is_a_precondition_failure(): void
    {
        $this->cacheToken();

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL)->assertStatus(412)->json('error'));
    }

    #[Test]
    public function no_connection_with_unresolved_mappings_is_still_the_connection_failure(): void
    {
        $this->cacheToken();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);
        self::assertGreaterThan(0, DB::table('quickbooks.mappings')->whereNull('qbo_ref_id')->count());

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL)->assertStatus(412)->json('error'));
    }

    #[Test]
    public function a_refused_refresh_is_a_precondition_failure(): void
    {
        $this->saveConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL)->assertStatus(412)->json('error'));
    }

    #[Test]
    public function a_transient_token_failure_is_unavailable(): void
    {
        $this->saveConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([], 503)]);

        $this->asRole('standard')->getJson(self::URL)->assertStatus(503);
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
        \config(["quickbooks.{$key}" => '']);

        $this->assertNotConnected($this->asRole('standard')->getJson(self::URL)->assertStatus(412)->json('error'));
    }

    #[Test]
    public function an_invalid_environment_is_a_server_error(): void
    {
        \config(['quickbooks.environment' => 'neither']);

        $this->asRole('standard')->getJson(self::URL)->assertStatus(500);
    }

    private function assertNotConnected(mixed $error): void
    {
        self::assertIsArray($error);
        self::assertSame('precondition_failed', $error['type']);
        self::assertArrayNotHasKey('errors', $error);
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
    private function insertSync(int $reference, array $state = []): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'status' => 'failed',
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
            ...$state,
        ]);
    }

    private function insertOrder(int $externalId, int $reference): void
    {
        DB::table('shopwired.orders')->insert([
            'external_id' => $externalId,
            'reference' => $reference,
            'total' => '29.990000',
            'sub_total_net' => '25.000000',
            'shipping_total_net' => '4.990000',
            'shipping_vat_rate' => '20.0',
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
            'order_placed_at' => '2026-11-01 10:00:00+00',
            'created_at' => '2026-11-01 10:00:00+00',
            'updated_at' => '2026-11-01 10:00:00+00',
        ]);
    }
}
