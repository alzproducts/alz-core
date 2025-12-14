<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationSnooze::class)]
final class ConversationSnoozeTest extends TestCase
{
    #[Test]
    public function it_creates_valid_snooze_with_user_id(): void
    {
        $snoozedUntil = new DateTimeImmutable('2024-12-20 10:00:00');

        $snooze = new ConversationSnooze(
            snoozedUntil: $snoozedUntil,
            snoozedByUserId: 12345,
        );

        $this->assertSame($snoozedUntil, $snooze->snoozedUntil);
        $this->assertSame(12345, $snooze->snoozedByUserId);
    }

    #[Test]
    public function it_creates_valid_snooze_with_null_user_id(): void
    {
        $snoozedUntil = new DateTimeImmutable('2024-12-25 14:30:00');

        $snooze = new ConversationSnooze(
            snoozedUntil: $snoozedUntil,
            snoozedByUserId: null,
        );

        $this->assertSame($snoozedUntil, $snooze->snoozedUntil);
        $this->assertNull($snooze->snoozedByUserId);
    }

    #[Test]
    public function it_preserves_datetime_precision(): void
    {
        $snoozedUntil = new DateTimeImmutable('2024-12-15 09:15:30.123456');

        $snooze = new ConversationSnooze(
            snoozedUntil: $snoozedUntil,
            snoozedByUserId: 99,
        );

        $this->assertSame(
            '2024-12-15 09:15:30.123456',
            $snooze->snoozedUntil->format('Y-m-d H:i:s.u'),
        );
    }
}
