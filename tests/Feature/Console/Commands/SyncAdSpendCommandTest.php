<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SyncAdSpendCommandTest extends TestCase
{
    #[Test]
    public function it_dispatches_job_with_provided_date(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync', ['--date' => '2024-11-20'])
            ->assertSuccessful();

        Queue::assertPushed(SyncGoogleAdsToMixpanelJob::class);
    }

    #[Test]
    public function it_defaults_to_yesterday_when_no_date_provided(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync')
            ->assertSuccessful();

        Queue::assertPushed(SyncGoogleAdsToMixpanelJob::class);
    }

    #[Test]
    public function it_rejects_invalid_date_format(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync', ['--date' => '2024/11/20'])
            ->assertFailed();

        Queue::assertNotPushed(SyncGoogleAdsToMixpanelJob::class);
    }

    #[Test]
    public function it_rejects_malformed_dates(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync', ['--date' => 'not-a-date'])
            ->assertFailed();

        Queue::assertNotPushed(SyncGoogleAdsToMixpanelJob::class);
    }

    #[Test]
    public function it_displays_success_message(): void
    {
        Queue::fake();

        $this->artisan('adspend:sync', ['--date' => '2024-11-20'])
            ->expectsOutput('Dispatching ad spend sync for 2024-11-20...')
            ->expectsOutput('Job dispatched successfully. Monitor progress in Horizon dashboard.')
            ->assertSuccessful();
    }
}
