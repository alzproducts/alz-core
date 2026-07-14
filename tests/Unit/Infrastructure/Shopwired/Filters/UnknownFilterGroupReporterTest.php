<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Filters;

use App\Infrastructure\Shopwired\Filters\UnknownFilterGroupReporter;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UnknownFilterGroupReporter::class)]
final class UnknownFilterGroupReporterTest extends TestCase
{
    private UnknownFilterGroupReporter $reporter;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = new UnknownFilterGroupReporter;
    }

    #[Test]
    public function emits_single_summary_when_one_option_no_recorded(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown filter group optionNos encountered - re-run SyncFilterGroupsJob',
                ['by_option_no' => [999 => 1]],
            );

        $this->reporter->record(999);

        $this->app->terminate();
    }

    #[Test]
    public function counts_repeats_of_same_option_no(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown filter group optionNos encountered - re-run SyncFilterGroupsJob',
                ['by_option_no' => [999 => 3]],
            );

        $this->reporter->record(999);
        $this->reporter->record(999);
        $this->reporter->record(999);

        $this->app->terminate();
    }

    #[Test]
    public function aggregates_distinct_option_nos_in_one_summary(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown filter group optionNos encountered - re-run SyncFilterGroupsJob',
                ['by_option_no' => [999 => 2, 1234 => 1]],
            );

        $this->reporter->record(999);
        $this->reporter->record(1234);
        $this->reporter->record(999);

        $this->app->terminate();
    }

    #[Test]
    public function emits_nothing_when_never_recorded(): void
    {
        Log::shouldReceive('warning')->never();

        $this->app->terminate();
    }
}
