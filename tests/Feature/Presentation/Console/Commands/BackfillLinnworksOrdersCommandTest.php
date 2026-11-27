<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\Linnworks\LinnworksBackfillDispatcherInterface;
use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Presentation\Console\Commands\BackfillLinnworksOrdersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(BackfillLinnworksOrdersCommand::class)]
final class BackfillLinnworksOrdersCommandTest extends TestCase
{
    private const array DATES = ['--from' => '2026-01-05', '--to' => '2026-02-03'];

    protected function setUp(): void
    {
        parent::setUp();

        // Resolving the injected use case builds the Linnworks client, which refuses blank credentials.
        \config([
            'linnworks.application_id' => 'test-application-id',
            'linnworks.application_secret' => 'test-application-secret',
            'linnworks.installation_token' => 'test-installation-token',
        ]);
    }

    #[Test]
    public function the_inline_querying_line_shows_british_dates(): void
    {
        $this->mock(OrderDashboardsClientInterface::class)->shouldReceive('getProcessedOrderIdsByOrderDate')->once()->andReturn([]);

        $this->artisan('linnworks:backfill-orders', self::DATES)
            ->expectsOutput('Querying Linnworks SQL API for order IDs (05/01/2026 to 03/02/2026)...')
            ->assertSuccessful();
    }

    #[Test]
    public function the_queued_dispatch_line_shows_british_dates(): void
    {
        $this->mock(LinnworksBackfillDispatcherInterface::class)->shouldReceive('dispatchDateRangeBackfill')->once();

        $this->artisan('linnworks:backfill-orders', [...self::DATES, '--queue' => true])
            ->expectsOutput('Dispatched backfill job to queue (05/01/2026 to 03/02/2026).')
            ->assertSuccessful();
    }
}
