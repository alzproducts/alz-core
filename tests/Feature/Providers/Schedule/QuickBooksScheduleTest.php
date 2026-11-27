<?php

declare(strict_types=1);

namespace Tests\Feature\Providers\Schedule;

use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderCatchUpJob;
use App\Providers\Schedule\QuickBooksScheduleServiceProvider;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Service providers are excluded from coverage, so this covers nothing.
 *
 * @see QuickBooksScheduleServiceProvider
 */
#[CoversNothing]
final class QuickBooksScheduleTest extends TestCase
{
    #[Test]
    public function the_token_refresh_job_runs_weekly_on_one_server(): void
    {
        $event = \array_find(
            $this->app->make(Schedule::class)->events(),
            static fn(Event $e): bool => $e->description === 'quickbooks-token-refresh',
        );

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 4 * * 1', $event->expression);
        $this->assertSame('Europe/London', $event->timezone);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    #[Test]
    public function the_order_catch_up_job_runs_hourly_on_one_server(): void
    {
        $event = $this->catchUpEvent();

        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertFalse($event->withoutOverlapping);
    }

    #[Test]
    public function each_hourly_run_queues_a_job_with_its_own_run_id(): void
    {
        Queue::fake();
        $event = $this->catchUpEvent();

        $event->run($this->app);
        $first = $this->pushedJobs();
        $this->assertCount(1, $first);
        new UniqueLock(Cache::driver())->release($first[0]);
        $event->run($this->app);

        $jobs = $this->pushedJobs();
        $this->assertCount(2, $jobs);
        $this->assertNotSame($jobs[0]->runId, $jobs[1]->runId);
        new UniqueLock(Cache::driver())->release($jobs[1]);
    }

    #[Test]
    public function no_second_job_is_queued_while_one_holds_the_unique_lock(): void
    {
        Queue::fake();
        $event = $this->catchUpEvent();

        $event->run($this->app);
        $event->run($this->app);

        $jobs = $this->pushedJobs();
        $this->assertCount(1, $jobs);
        new UniqueLock(Cache::driver())->release($jobs[0]);
    }

    #[Test]
    public function the_catch_up_job_is_unique_for_one_schedule_interval(): void
    {
        $job = new SyncQuickBooksOrderCatchUpJob();

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame([1, 'low'], [$job->tries, $job->queue]);
    }

    private function catchUpEvent(): Event
    {
        $event = \array_find(
            $this->app->make(Schedule::class)->events(),
            static fn(Event $e): bool => $e->description === 'quickbooks-order-catch-up',
        );
        $this->assertInstanceOf(Event::class, $event);

        return $event;
    }

    /**
     * @return list<SyncQuickBooksOrderCatchUpJob>
     */
    private function pushedJobs(): array
    {
        return \array_values(Queue::pushed(SyncQuickBooksOrderCatchUpJob::class)->all());
    }
}
