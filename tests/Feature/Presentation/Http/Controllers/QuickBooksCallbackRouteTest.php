<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Http\QuickBooks\Controllers\QuickBooksCallbackController;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(QuickBooksCallbackController::class)]
final class QuickBooksCallbackRouteTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = '9130000000000001';

    private int $tokenRequests = 0;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(new CarbonImmutable('2026-11-18T10:00:00+00:00'));
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
    }

    #[Test]
    public function a_valid_callback_stores_one_encrypted_connection(): void
    {
        $this->fakeIntuit(Http::response([
            'access_token' => 'access-1',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-1',
            'x_refresh_token_expires_in' => 8_726_400,
        ]));

        $response = $this->hitCallback(['state' => $this->issueState(), 'code' => 'auth-code', 'realmId' => self::REALM]);

        $response->assertOk();
        $response->assertSeeText('QuickBooks connected');
        $this->assertSame(1, $this->tokenRequests);
        $row = DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->sole();
        $this->assertIsString($row->encrypted_refresh_token);
        $this->assertStringNotContainsString('refresh-1', $row->encrypted_refresh_token);
        $this->assertSame('refresh-1', $this->app->make(ApiKeyCipherInterface::class)->decrypt($row->encrypted_refresh_token)->value);
    }

    /**
     * @param array<string, mixed> $query
     */
    #[Test]
    #[DataProvider('rejectedCallbacks')]
    public function a_rejected_callback_answers_400_without_a_token_request(array $query): void
    {
        $this->fakeIntuit(Http::response([], Response::HTTP_INTERNAL_SERVER_ERROR));
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-existing'),
            refreshTokenExpiresAt: new CarbonImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        ));
        $before = $this->storedRow();
        $state = $this->issueState();
        $query = \array_map(static fn(mixed $v): mixed => $v === '{state}' ? $state : $v, $query);

        $response = $this->hitCallback($query);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame(0, $this->tokenRequests);
        $this->assertEquals($before, $this->storedRow());
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(): array
    {
        return (array) DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->sole();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedCallbacks(): array
    {
        return [
            'missing state' => [['code' => 'auth-code', 'realmId' => self::REALM]],
            'unknown state' => [['state' => \str_repeat('a', 64), 'code' => 'auth-code', 'realmId' => self::REALM]],
            'array state' => [['state' => ['x'], 'code' => 'auth-code', 'realmId' => self::REALM]],
            'overlong state' => [['state' => \str_repeat('a', 4096), 'code' => 'auth-code', 'realmId' => self::REALM]],
            'missing code' => [['state' => '{state}', 'realmId' => self::REALM]],
            'wrong realm' => [['state' => '{state}', 'code' => 'auth-code', 'realmId' => '123']],
        ];
    }

    #[Test]
    public function a_used_state_is_rejected_the_second_time(): void
    {
        $this->fakeIntuit(Http::response([
            'access_token' => 'access-1',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-1',
            'x_refresh_token_expires_in' => 8_726_400,
        ]));
        $query = ['state' => $this->issueState(), 'code' => 'auth-code', 'realmId' => self::REALM];

        $this->hitCallback($query)->assertOk();
        $afterFirst = $this->storedRow();
        $this->hitCallback($query)->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(1, $this->tokenRequests);
        $this->assertEquals($afterFirst, $this->storedRow());
    }

    #[Test]
    public function a_failed_code_exchange_answers_502_with_the_reconnect_hint(): void
    {
        $this->fakeIntuit(Http::response(['error' => 'invalid_grant'], Response::HTTP_BAD_REQUEST));

        $response = $this->hitCallback(['state' => $this->issueState(), 'code' => 'auth-code', 'realmId' => self::REALM]);

        $response->assertStatus(Response::HTTP_BAD_GATEWAY);
        $response->assertSeeText('php artisan quickbooks:connect');
        $this->assertSame(0, DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->count());
    }

    private function issueState(): string
    {
        return $this->app->make(QuickBooksStateStoreInterface::class)->issue();
    }

    private function fakeIntuit(mixed $response): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => function (Request $request) use ($response): mixed {
            $this->tokenRequests++;

            return $response;
        }]);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return TestResponse<Response>
     */
    private function hitCallback(array $query): TestResponse
    {
        return $this->get('/quickbooks/callback?' . \http_build_query($query));
    }
}
