<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Slack notification sent when contact form processing fails after all retries.
 *
 * Provides detailed context to help support team follow up with the customer
 * and developers to diagnose the failure cause.
 */
final class ContactFormFailedNotification extends Notification
{
    public function __construct(
        public readonly ContactSubmission $submission,
        public readonly string $submissionId,
        public readonly string $errorMessage,
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
        $form = $this->submission->form;
        $product = $this->submission->product;

        $message = (new SlackMessage())
            ->text("Contact form processing failed for {$form->email}")
            ->headerBlock('⚠️ Contact Form Processing Failed')
            ->sectionBlock(static function (SectionBlock $block) use ($form): void {
                $block->field("*Email:*\n{$form->email}");
                $block->field("*Name:*\n{$form->name}");

                if ($form->phone !== null) {
                    $block->field("*Phone:*\n{$form->phone}");
                }
            })
            ->sectionBlock(static function (SectionBlock $block) use ($form): void {
                $block->text("*Reason:*\n{$form->reason->label()}");
            })
            ->sectionBlock(static function (SectionBlock $block) use ($form): void {
                // Truncate message to ~500 chars to keep notification readable
                $truncatedMessage = \mb_strlen($form->message) > 500
                    ? \mb_substr($form->message, 0, 497) . '...'
                    : $form->message;

                $block->text("*Message:*\n{$truncatedMessage}");
            });

        // Add product context if present
        if ($product !== null) {
            $message->sectionBlock(static function (SectionBlock $block) use ($product): void {
                $productText = "*Product:*\n{$product->sku}";
                if ($product->title !== null) {
                    $productText .= " - {$product->title}";
                }
                $block->text($productText);
            });
        }

        // Add order/postcode metadata if present
        $metadata = [];
        if ($form->orderNumber !== null) {
            $metadata[] = "*Order:* {$form->orderNumber}";
        }
        if ($form->deliveryPostcode !== null) {
            $metadata[] = "*Postcode:* {$form->deliveryPostcode}";
        }
        if ($metadata !== []) {
            $message->sectionBlock(static function (SectionBlock $block) use ($metadata): void {
                $block->text(\implode('  |  ', $metadata));
            });
        }

        // Add error details
        $message
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*Error:*\n```{$this->errorMessage}```");
            })
            ->contextBlock(function (ContextBlock $block): void {
                $block->text("Submission ID: {$this->submissionId}");
            });

        return $message;
    }
}
