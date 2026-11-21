<?php

declare(strict_types=1);

namespace Tests\Feature\Application\QuickBooks;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Container-wired: the real encrypting repository, the configured token cache and lock, and a faked Intuit.
 */
#[CoversClass(QuickBooksTokenService::class)]
final class QuickBooksTokenServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $sentRefreshTokens = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(new CarbonImmutable('2026-11-18T10:00:00+00:00'));
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-0'),
            refreshTokenExpiresAt: new CarbonImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        ));
        Http::fake([QuickBooksConfig::TOKEN_URL => function (Request $request) {
            $sent = $request->data()['refresh_token'] ?? null;
            $this->sentRefreshTokens[] = \is_string($sent) ? $sent : '';
            $n = \count($this->sentRefreshTokens);

            return Http::response([
                'access_token' => "access-{$n}",
                'expires_in' => 3600,
                'refresh_token' => "refresh-{$n}",
                'x_refresh_token_expires_in' => 8_726_400,
            ]);
        }]);
    }

    #[Test]
    public function a_rotated_refresh_token_is_stored_encrypted_and_sent_on_the_next_refresh(): void
    {
        $service = $this->app->make(QuickBooksTokenService::class);

        $service->forceRefresh();
        $this->assertSame('refresh-1', $this->storedRefreshToken());

        $service->forceRefresh();

        $this->assertSame(['refresh-0', 'refresh-1'], $this->sentRefreshTokens);
        $this->assertSame('refresh-2', $this->storedRefreshToken());
    }

    #[Test]
    public function two_access_token_calls_within_its_lifetime_send_one_token_request(): void
    {
        $service = $this->app->make(QuickBooksTokenService::class);

        $this->assertSame('access-1', $service->accessToken()->value);
        $this->assertSame('access-1', $service->accessToken()->value);

        $this->assertSame(['refresh-0'], $this->sentRefreshTokens);
    }

    private function storedRefreshToken(): string
    {
        $row = DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->sole();
        $this->assertIsString($row->encrypted_refresh_token);
        $this->assertStringNotContainsString('refresh-', $row->encrypted_refresh_token);

        return $this->app->make(ApiKeyCipherInterface::class)->decrypt($row->encrypted_refresh_token)->value;
    }
}
