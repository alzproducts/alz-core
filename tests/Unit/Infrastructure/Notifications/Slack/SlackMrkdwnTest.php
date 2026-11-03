<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Infrastructure\Notifications\Slack\SlackMrkdwn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SlackMrkdwn::class)]
final class SlackMrkdwnTest extends TestCase
{
    #[Test]
    public function escapes_control_characters_without_double_escaping(): void
    {
        self::assertSame(
            '&lt;!channel&gt; &amp; &lt;https://x.example|y&gt;',
            SlackMrkdwn::escape('<!channel> & <https://x.example|y>'),
        );
    }

    #[Test]
    public function leaves_ordinary_text_unchanged(): void
    {
        self::assertSame('Jane Smith *bold* _it_', SlackMrkdwn::escape('Jane Smith *bold* _it_'));
    }
}
