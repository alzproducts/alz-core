<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification sent when a contact form is successfully processed.
 *
 * Sent to the verbose channel for monitoring/audit purposes.
 * Includes a link to view the conversation in HelpScout.
 */
final class ContactFormProcessedNotification extends Notification
{
    public function __construct(
        public readonly int $conversationId,
        public readonly string $customerName,
        public readonly string $customerEmail,
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
        $helpScoutUrl = "https://secure.helpscout.net/conversation/{$this->conversationId}";

        return (new SlackMessage())
            ->text("New contact form processed for {$this->customerName}")
            ->headerBlock('✅ Contact Form Processed')
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("New contact form submitted by *{$this->customerName}* ({$this->customerEmail})")->markdown();
            })
            ->actionsBlock(static function (ActionsBlock $block) use ($helpScoutUrl): void {
                $block->button('View in HelpScout')
                    ->url($helpScoutUrl)
                    ->primary();
            });
    }
}
