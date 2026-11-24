<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncDeadlineResolver;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderSyncDeadlineResolver::class)]
final class OrderSyncDeadlineResolverTest extends TestCase
{
    #[Test]
    public function the_callers_deadline_stands_when_nothing_was_claimed(): void
    {
        $caller = Deadline::at(new DateTimeImmutable('2026-11-21 10:00:00'));

        self::assertSame($caller, OrderSyncDeadlineResolver::resolve([], $caller));
    }
}
