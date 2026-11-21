<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\QuickBooks;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Application\QuickBooks\UseCases\UpdateQuickBooksConnectionTokenUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\QuickBooks\UpdateQuickBooksConnectionTokenJob;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UpdateQuickBooksConnectionTokenJob::class)]
#[CoversClass(UpdateQuickBooksConnectionTokenUseCase::class)]
final class UpdateQuickBooksConnectionTokenJobTest extends TestCase
{
    use DatabaseTransactions;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(new CarbonImmutable('2026-11-18T10:00:00+00:00'));
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
    }

    #[Test]
    public function it_refreshes_even_with_a_valid_cached_access_token_and_stores_the_new_refresh_token(): void
    {
        $this->storeConnection();
        $this->fakeTokenEndpoint();
        $this->app->make(QuickBooksTokenService::class)->accessToken();

        $this->runJob();

        Http::assertSentCount(2);
        $this->assertSame('refresh-2', $this->storedRefreshToken());
    }

    #[Test]
    public function with_no_connection_it_sends_nothing_and_completes(): void
    {
        $job = (new UpdateQuickBooksConnectionTokenJob())->withFakeQueueInteractions();

        $this->runThroughMiddleware($job);

        Http::assertNothingSent();
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    #[Test]
    public function an_invalid_grant_fails_the_job_without_retry(): void
    {
        $this->storeConnection();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $job = (new UpdateQuickBooksConnectionTokenJob())->withFakeQueueInteractions();

        $this->runThroughMiddleware($job);

        $job->assertFailed();
        $job->assertNotReleased();
        $this->assertSame('refresh-0', $this->storedRefreshToken());
    }

    #[Test]
    public function it_handles_api_exceptions_through_its_middleware(): void
    {
        $middleware = (new UpdateQuickBooksConnectionTokenJob())->middleware();

        $this->assertNotEmpty(\array_filter($middleware, static fn(object $m): bool => $m instanceof HandleApiExceptions));
    }

    private function runJob(): void
    {
        $this->runThroughMiddleware((new UpdateQuickBooksConnectionTokenJob())->withFakeQueueInteractions());
    }

    private function runThroughMiddleware(UpdateQuickBooksConnectionTokenJob $job): void
    {
        $useCase = $this->app->make(UpdateQuickBooksConnectionTokenUseCase::class);

        (new HandleApiExceptions())->handle($job, static function (UpdateQuickBooksConnectionTokenJob $job) use ($useCase): void {
            $job->handle($useCase);
        });
    }

    private function storeConnection(): void
    {
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-0'),
            refreshTokenExpiresAt: new CarbonImmutable('2027-02-26T10:00:00+00:00'),
            refreshTokenHardExpiresAt: null,
        ));
    }

    private function fakeTokenEndpoint(): void
    {
        $n = 0;
        Http::fake([QuickBooksConfig::TOKEN_URL => static function () use (&$n) {
            $n++;

            return Http::response([
                'access_token' => "access-{$n}",
                'expires_in' => 3600,
                'refresh_token' => "refresh-{$n}",
                'x_refresh_token_expires_in' => 8_726_400,
            ]);
        }]);
    }

    private function storedRefreshToken(): string
    {
        $row = DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->sole();
        $this->assertIsString($row->encrypted_refresh_token);

        return $this->app->make(ApiKeyCipherInterface::class)->decrypt($row->encrypted_refresh_token)->value;
    }
}
