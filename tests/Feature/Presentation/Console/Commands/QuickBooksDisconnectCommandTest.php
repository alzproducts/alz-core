<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Console\Commands\QuickBooksDisconnectCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QuickBooksDisconnectCommand::class)]
final class QuickBooksDisconnectCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-0'),
            refreshTokenExpiresAt: new CarbonImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        ));
    }

    #[Test]
    public function a_successful_revoke_sends_the_stored_refresh_token_and_deletes_the_row(): void
    {
        Http::fake([QuickBooksConfig::REVOKE_URL => Http::response()]);

        $this->artisan('quickbooks:disconnect')->assertSuccessful();

        Http::assertSent(static fn(Request $r): bool => $r->url() === 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke'
            && $r->method() === 'POST'
            && $r->data() === ['token' => 'refresh-0']);
        $this->assertSame(0, $this->rowCount());
    }

    #[Test]
    public function a_revoke_error_exits_non_zero_and_keeps_the_row(): void
    {
        Http::fake([QuickBooksConfig::REVOKE_URL => Http::response(['error' => 'server_error'], 500)]);

        $this->artisan('quickbooks:disconnect')
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertSame(1, $this->rowCount());
    }

    #[Test]
    public function a_rejected_revoke_exits_non_zero_and_keeps_the_row(): void
    {
        Http::fake([QuickBooksConfig::REVOKE_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->artisan('quickbooks:disconnect')->assertFailed();

        $this->assertSame(1, $this->rowCount());
    }

    #[Test]
    public function force_deletes_the_row_when_the_revoke_fails_and_says_to_revoke_by_hand(): void
    {
        Http::fake([QuickBooksConfig::REVOKE_URL => Http::response(['error' => 'server_error'], 500)]);

        $this->artisan('quickbooks:disconnect', ['--force' => true])
            ->expectsOutputToContain('Intuit app')
            ->assertSuccessful();

        $this->assertSame(0, $this->rowCount());
    }

    #[Test]
    public function a_dry_run_sends_nothing_and_keeps_the_row(): void
    {
        Http::fake();

        $this->artisan('quickbooks:disconnect', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        return DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->count();
    }
}
