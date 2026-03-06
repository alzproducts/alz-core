<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use DateTimeImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification for admin alerts requiring investigation.
 *
 * Displays title, message body, optional context fields, and the timestamp
 * from when the originating event was fired (not when the queue processed it).
 * Queuing is handled by the listener — this notification runs synchronously within it.
 */
final class AdminAlertNotification extends Notification
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

        if ($this->context !== []) {
            $contextText = \implode(' | ', \array_map(
                static fn(string $key, mixed $value): string => \sprintf('*%s:* %s', $key, \print_r($value, true)),
                \array_keys($this->context),
                $this->context,
            ));

            $slackMessage->sectionBlock(static function (SectionBlock $block) use ($contextText): void {
                $block->text($contextText)->markdown();
            });
        }

        $firedAt = $this->firedAt->format('Y-m-d H:i:s T');

        return $slackMessage->contextBlock(static function (ContextBlock $block) use ($firedAt): void {
            $block->text("Event fired at {$firedAt}");
        });
    }
}
