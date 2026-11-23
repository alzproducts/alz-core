<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\CacheQuickBooksAccessTokenCache;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Console\Commands\QuickBooksImportAccessTokenCommand;
use App\Presentation\Console\Commands\QuickBooksLeaseAccessTokenCommand;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStdin;
use Tests\TestCase;

#[CoversClass(QuickBooksLeaseAccessTokenCommand::class)]
#[CoversClass(QuickBooksImportAccessTokenCommand::class)]
#[CoversClass(CacheQuickBooksAccessTokenCache::class)]
final class QuickBooksTokenLeaseCommandsTest extends TestCase
{
    use DatabaseTransactions;

    private const string TOKEN_KEY = 'quickbooks:access-token:sandbox';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config(['app.env' => 'local', 'quickbooks.environment' => 'sandbox']);
        Http::preventStrayRequests();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response([
            'access_token' => 'access-1',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-1',
            'x_refresh_token_expires_in' => 8_726_400,
        ])]);
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-0'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
        $this->cache()->forget();
    }

    #[Test]
    public function the_lease_prints_the_cached_token_and_expiry_without_a_request(): void
    {
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds(QuickBooksTokenService::LEASE_FLOOR_SECONDS + 30)->startOfSecond();
        $this->cache()->put(new ApiKeyToken('cached'), $expiresAt);

        $this->assertSame(0, Artisan::call('quickbooks:lease-access-token'));

        $this->assertSame(self::leaseJson('cached', $expiresAt), \mb_trim(Artisan::output()));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_lease_below_the_floor_refreshes_once_and_prints_the_new_token(): void
    {
        $this->cache()->put(new ApiKeyToken('cached'), CarbonImmutable::now()->addSeconds(QuickBooksTokenService::LEASE_FLOOR_SECONDS - 30));

        $this->assertSame(0, Artisan::call('quickbooks:lease-access-token'));
        $first = \mb_trim(Artisan::output());
        $this->assertSame(0, Artisan::call('quickbooks:lease-access-token'));

        $expiresAt = $this->cache()->expiresAt();
        $this->assertNotNull($expiresAt);
        $this->assertSame(self::leaseJson('access-1', $expiresAt), $first);
        $this->assertSame($first, \mb_trim(Artisan::output()));
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_cached_token_with_no_stored_expiry_is_refreshed(): void
    {
        $this->store()->put(self::TOKEN_KEY, 'cached', 3600);

        $this->assertSame(0, Artisan::call('quickbooks:lease-access-token'));

        $this->assertStringContainsString('"access-1"', Artisan::output());
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_lease_without_a_connection_fails_and_prints_nothing(): void
    {
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->cache()->put(new ApiKeyToken('cached'), CarbonImmutable::now()->addHour());

        $this->assertSame(1, Artisan::call('quickbooks:lease-access-token'));

        $this->assertSame('', Artisan::output());
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function refusedEnvironments(): array
    {
        return [
            'production app' => ['production', 'sandbox'],
            'testing app' => ['testing', 'sandbox'],
            'production company' => ['local', 'production'],
            'unset company' => ['local', null],
        ];
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function the_lease_refuses_anything_but_the_local_sandbox(string $appEnv, ?string $environment): void
    {
        $this->cache()->put(new ApiKeyToken('cached'), CarbonImmutable::now()->addSeconds(60 + 30));
        \config(['app.env' => $appEnv, 'quickbooks.environment' => $environment]);

        $this->assertSame(1, Artisan::call('quickbooks:lease-access-token'));

        $output = Artisan::output();
        $this->assertStringContainsString('Refused', $output);
        $this->assertStringNotContainsString('cached', $output);
        $this->assertSame('cached', $this->store()->get(self::TOKEN_KEY));
        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function the_import_refuses_anything_but_the_local_sandbox(string $appEnv, ?string $environment): void
    {
        \config(['app.env' => $appEnv, 'quickbooks.environment' => $environment]);

        [$exit, $output] = $this->import(self::leaseJson('imported', CarbonImmutable::now()->addHour()));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refused', $output);
        $this->assertNull($this->store()->get(self::TOKEN_KEY));
        $this->assertNull($this->store()->get('quickbooks:access-token:production'));
    }

    #[Test]
    public function the_import_stores_the_token_for_its_remaining_life_and_prints_only_the_expiry(): void
    {
        $this->travelTo(new CarbonImmutable('2026-11-18T10:00:00+00:00'));
        $expiresAt = new CarbonImmutable('2026-11-18T11:00:00+00:00');

        [$exit, $output] = $this->import(self::leaseJson('imported', $expiresAt) . "\n");

        $this->assertSame(0, $exit);
        $this->assertSame('2026-11-18T11:00:00+00:00', \mb_trim($output));
        $this->assertSame('imported', $this->store()->get(self::TOKEN_KEY));
        $this->assertSame($expiresAt->getTimestamp(), $this->cache()->expiresAt()?->getTimestamp());

        $this->travelTo($expiresAt->subSeconds(61));
        $this->assertSame('imported', $this->store()->get(self::TOKEN_KEY));
        $this->travelTo($expiresAt->subSeconds(59));
        $this->assertNull($this->store()->get(self::TOKEN_KEY));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableInput(): array
    {
        return [
            'empty' => [''],
            'not JSON' => ['secret-token'],
            'JSON scalar' => ['"secret-token"'],
            'no expiry' => ['{"access_token":"secret-token"}'],
            'no token' => ['{"expires_at":"2099-01-01T00:00:00+00:00"}'],
            'blank token' => ['{"access_token":"","expires_at":"2099-01-01T00:00:00+00:00"}'],
            'token with whitespace' => ['{"access_token":"secret token","expires_at":"2099-01-01T00:00:00+00:00"}'],
            'bad expiry' => ['{"access_token":"secret-token","expires_at":"soon"}'],
            'expired' => ['{"access_token":"secret-token","expires_at":"2020-01-01T00:00:00+00:00"}'],
        ];
    }

    #[Test]
    #[DataProvider('unusableInput')]
    public function unusable_stdin_fails_and_leaves_the_stored_token_unchanged(string $stdin): void
    {
        $expiresAt = CarbonImmutable::now()->addHour();
        $this->cache()->put(new ApiKeyToken('stored'), $expiresAt);

        [$exit, $output] = $this->import($stdin);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertSame('stored', $this->store()->get(self::TOKEN_KEY));
        $this->assertSame($expiresAt->getTimestamp(), $this->cache()->expiresAt()?->getTimestamp());
    }

    #[Test]
    public function run_labels_are_blank_outside_a_verify_run(): void
    {
        $this->assertSame('', \config('quickbooks.doc_number_prefix'));
        $this->assertSame('', \config('quickbooks.customer_name_suffix'));
    }

    /**
     * @return array{int, string}
     */
    private function import(string $stdin): array
    {
        $exit = FakeStdin::during($stdin, static fn(): int => Artisan::call('quickbooks:import-access-token'));

        return [$exit, Artisan::output()];
    }

    private function cache(): QuickBooksAccessTokenCacheInterface
    {
        return $this->app->make(QuickBooksAccessTokenCacheInterface::class);
    }

    private function store(): Repository
    {
        return Cache::store('array');
    }

    private static function leaseJson(string $token, DateTimeInterface $expiresAt): string
    {
        return (string) \json_encode(
            ['access_token' => $token, 'expires_at' => $expiresAt->format(DateTimeInterface::ATOM)],
            \JSON_UNESCAPED_SLASHES,
        );
    }
}
