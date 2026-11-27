<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Infrastructure\Notifications\Slack\AlertNotification;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(AlertNotification::class)]
final class AlertNotificationTest extends TestCase
{
    #[Test]
    public function event_fired_footer_shows_a_british_date_time_and_timezone(): void
    {
        $firedAt = new DateTimeImmutable('2026-07-05 14:30:09', new DateTimeZone('Europe/London'));

        $payload = (new AlertNotification('Title', 'Message', [], $firedAt))->toSlack(new stdClass())->toArray();

        self::assertStringContainsString('"text":"Event fired at 05\/07\/2026 14:30:09 BST"', \json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
