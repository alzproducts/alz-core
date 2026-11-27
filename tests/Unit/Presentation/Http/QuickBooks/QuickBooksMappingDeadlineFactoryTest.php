<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\QuickBooks;

use App\Presentation\Http\QuickBooks\QuickBooksMappingDeadlineFactory;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class QuickBooksMappingDeadlineFactoryTest extends TestCase
{
    #[Test]
    public function the_deadline_counts_from_the_requests_arrival(): void
    {
        $request = Request::create('/api/quickbooks/mappings/check', server: ['REQUEST_TIME_FLOAT' => 1_800_000_000.25]);

        $deadline = QuickBooksMappingDeadlineFactory::forRequest($request);

        self::assertSame('1800000024.250000', $deadline->at->format('U.u'));
    }

    #[Test]
    public function without_an_arrival_time_the_deadline_counts_from_now(): void
    {
        $request = Request::create('/api/quickbooks/mappings/check');
        $request->server->remove('REQUEST_TIME_FLOAT');
        $before = new DateTimeImmutable();

        $secondsLeft = QuickBooksMappingDeadlineFactory::forRequest($request)->secondsLeft($before);

        self::assertGreaterThanOrEqual(24.0, $secondsLeft);
        self::assertLessThan(25.0, $secondsLeft);
    }
}
