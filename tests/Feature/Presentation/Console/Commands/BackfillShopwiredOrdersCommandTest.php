<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Presentation\Console\Commands\BackfillShopwiredOrdersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(BackfillShopwiredOrdersCommand::class)]
final class BackfillShopwiredOrdersCommandTest extends TestCase
{
    #[Test]
    public function the_dry_run_job_table_shows_british_dates_with_times(): void
    {
        $this->travelTo('2026-03-15 14:30:00');
        $this->mock(ShopwiredSyncDispatcherInterface::class)->shouldNotReceive('dispatchOrdersRangeSync');

        $this->artisan('shopwired:backfill-orders', ['--months' => 2, '--dry-run' => true])
            ->expectsTable(['#', 'From', 'To', 'Period'], [
                [1, '01/03/2026 00:00', '15/03/2026 14:30', 'Mar 2026 (partial)'],
                [2, '01/02/2026 00:00', '01/03/2026 00:00', 'Feb 2026'],
            ])
            ->assertSuccessful();
    }
}
