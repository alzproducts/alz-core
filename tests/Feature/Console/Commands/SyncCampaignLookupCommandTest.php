<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SyncCampaignLookupCommandTest extends TestCase
{
    #[Test]
    public function it_dispatches_campaign_lookup_job(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync-lookup')
            ->assertSuccessful();

        Queue::assertPushed(SyncCampaignLookupTableJob::class);
    }

    #[Test]
    public function it_displays_success_message(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync-lookup')
            ->expectsOutput('Dispatching campaign lookup table sync...')
            ->expectsOutput('Job dispatched successfully. Monitor progress in Horizon dashboard.')
            ->assertSuccessful();
    }
}
