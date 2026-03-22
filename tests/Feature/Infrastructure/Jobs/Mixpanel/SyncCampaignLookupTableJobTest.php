<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Jobs\Mixpanel;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncCampaignLookupTableJob Feature Tests.
 *
 * Tests the job's success path and middleware configuration.
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncCampaignLookupTableJob::class)]
final class SyncCampaignLookupTableJobTest extends TestCase
{
    private LookupTableProviderInterface&MockInterface $providerMock;

    private MixpanelClientInterface&MockInterface $mixpanelMock;

    private SyncLookupTableUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerMock = Mockery::mock(LookupTableProviderInterface::class);
        $this->mixpanelMock = Mockery::mock(MixpanelClientInterface::class);
        $loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->useCase = new SyncLookupTableUseCase($this->providerMock, $this->mixpanelMock, $loggerMock);

        // Setup default provider metadata
        $this->providerMock->shouldReceive('getTableKey')->andReturn('utm_campaigns')->byDefault();
        $this->providerMock->shouldReceive('getSourceName')->andReturn('Google Ads')->byDefault();
        $this->providerMock->shouldReceive('getHeaders')->andReturn(['utm_campaign', 'campaign_name', 'campaign_status'])->byDefault();
    }

    // ========================================================================
    // Middleware Tests
    // ========================================================================

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncCampaignLookupTableJob();
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_executes_successfully(): void
    {
        $this->setupSuccessfulSync();

        $job = new SyncCampaignLookupTableJob();

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_passes_rows_to_use_case_for_synchronization(): void
    {
        $rows = [
            ['111', 'Campaign One', 'ENABLED'],
            ['222', 'Campaign Two', 'PAUSED'],
        ];

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelMock
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->with('utm_campaigns', ['utm_campaign', 'campaign_name', 'campaign_status'], $rows);

        $job = new SyncCampaignLookupTableJob();

        $job->handle($this->useCase);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function setupSuccessfulSync(): void
    {
        $rows = [
            ['123456789', '[01] Search - Branded', 'ENABLED'],
        ];

        $this->providerMock
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelMock
            ->shouldReceive('replaceLookupTable')
            ->once();
    }

}
