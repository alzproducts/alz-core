<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use DateTimeImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification for alerts requiring investigation.
 *
 * Displays title, message body, optional context fields, and the timestamp
 * from when the originating event was fired (not when the queue processed it).
 * Queuing is handled by the listener — this notification runs synchronously within it.
 * The target channel (admin vs manager) is chosen by the caller, not this class.
 */
final class AlertNotification extends Notification
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly array $context,
        private readonly DateTimeImmutable $firedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $slackMessage = (new SlackMessage())
            ->text($this->title)
            ->headerBlock($this->title)
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->message)->markdown();
            });

        $this->appendContextSection($slackMessage);

        $firedAt = $this->firedAt->format('Y-m-d H:i:s T');

        return $slackMessage->contextBlock(static function (ContextBlock $block) use ($firedAt): void {
            $block->text("Event fired at {$firedAt}");
        });
    }

    private function appendContextSection(SlackMessage $message): void
    {
        if ($this->context === []) {
            return;
        }

        $contextText = \implode("\n", \array_map(
            static fn(mixed $value): string => '• ' . self::formatValue($value),
            $this->context,
        ));

        $message->sectionBlock(static function (SectionBlock $block) use ($contextText): void {
            $block->text($contextText)->markdown();
        });
    }

    private static function formatValue(mixed $value): string
    {
        if (\is_scalar($value)) {
            return (string) $value;
        }

        $encoded = \json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '(unencodable)' : $encoded;
    }
}
