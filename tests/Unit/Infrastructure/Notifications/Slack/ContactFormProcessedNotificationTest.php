<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Infrastructure\Notifications\Slack\ContactFormProcessedNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(ContactFormProcessedNotification::class)]
final class ContactFormProcessedNotificationTest extends TestCase
{
    #[Test]
    public function section_block_escapes_mention_in_customer_name(): void
    {
        $sectionText = self::sectionText(self::payload('<!channel> urgent', 'jane@example.com'));

        self::assertStringContainsString('&lt;!channel&gt; urgent', $sectionText);
        self::assertStringNotContainsString('<!channel>', $sectionText);
    }

    #[Test]
    public function fallback_text_escapes_mentions_in_name_and_email(): void
    {
        $payload = self::payload('<!channel> urgent', '<!here>@x.example');

        self::assertSame('New contact form processed for &lt;!channel&gt; urgent', $payload['text']);
        self::assertStringNotContainsString('<!here>', self::sectionText($payload));
    }

    #[Test]
    public function keeps_formatting_for_ordinary_input(): void
    {
        $payload = self::payload('Jane Smith', 'jane@example.com');

        self::assertSame('New contact form processed for Jane Smith', $payload['text']);
        self::assertSame('New contact form submitted by *Jane Smith* (jane@example.com)', self::sectionText($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $name, string $email): array
    {
        return (new ContactFormProcessedNotification(123, $name, $email))
            ->toSlack(new stdClass())
            ->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sectionText(array $payload): string
    {
        /** @var list<array{type: string, text?: array{text: string}}> $blocks */
        $blocks = $payload['blocks'];
        $section = \array_find($blocks, static fn(array $block): bool => $block['type'] === 'section');
        self::assertNotNull($section);
        self::assertArrayHasKey('text', $section);

        return $section['text']['text'];
    }
}
