<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderBackfillDispatcherInterface;
use App\Presentation\Console\Commands\BackfillPurchaseOrdersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(BackfillPurchaseOrdersCommand::class)]
final class BackfillPurchaseOrdersCommandTest extends TestCase
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
        $this->mock(PurchaseDashboardsClientInterface::class)->shouldReceive('getPurchaseOrderIdsByDateRange')->once()->andReturn([]);

        $this->artisan('linnworks:backfill-purchase-orders', self::DATES)
            ->expectsOutput('Querying Linnworks SQL API for purchase order IDs (05/01/2026 to 03/02/2026)...')
            ->assertSuccessful();
    }

    #[Test]
    public function the_queued_dispatch_line_shows_british_dates(): void
    {
        $this->mock(PurchaseOrderBackfillDispatcherInterface::class)->shouldReceive('dispatchDateRangeBackfill')->once();

        $this->artisan('linnworks:backfill-purchase-orders', [...self::DATES, '--queue' => true])
            ->expectsOutput('Dispatched purchase order backfill to queue (05/01/2026 to 03/02/2026).')
            ->assertSuccessful();
    }
}
