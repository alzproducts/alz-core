<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Infrastructure\Notifications\Slack\VariantSkusGeneratedNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(VariantSkusGeneratedNotification::class)]
final class VariantSkusGeneratedNotificationTest extends TestCase
{
    #[Test]
    public function generated_footer_shows_a_british_date_and_time(): void
    {
        $this->travelTo('2026-01-05 14:30:09');

        $payload = (new VariantSkusGeneratedNotification(42, 'Oak Table', 0, 0, 0, []))->toSlack(new stdClass())->toArray();

        self::assertStringContainsString('"text":"Generated at 05\/01\/2026 14:30:09"', \json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
