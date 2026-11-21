<?php

declare(strict_types=1);

namespace Tests\Feature\Providers\Schedule;

use App\Providers\Schedule\QuickBooksScheduleServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
}
