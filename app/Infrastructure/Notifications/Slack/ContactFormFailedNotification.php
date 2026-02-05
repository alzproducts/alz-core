<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Slack;

use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
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
        public readonly ?bool $emailValid = null,
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

        $message = (new SlackMessage())
            ->text("Contact form processing failed for {$form->email}")
            ->headerBlock('⚠️ Contact Form Processing Failed');

        $this->addCustomerSection($message, $form);
        $this->addReasonSection($message, $form);
        $this->addMessageSection($message, $form);
        $this->addProductSection($message);
        $this->addMetadataSection($message, $form);
        $this->addEmailValiditySection($message);
        $this->addErrorSection($message);

        return $message;
    }

    private function addCustomerSection(SlackMessage $message, ContactFormData $form): void
    {
        $customerDetails = "*Email:* {$form->email}\n*Name:* {$form->name}";
        if ($form->phone !== null) {
            $customerDetails .= "\n*Phone:* {$form->phone}";
        }

        $message->sectionBlock(static function (SectionBlock $block) use ($customerDetails): void {
            $block->text($customerDetails)->markdown();
        });
    }

    private function addReasonSection(SlackMessage $message, ContactFormData $form): void
    {
        $message->sectionBlock(static function (SectionBlock $block) use ($form): void {
            $block->text("*Reason:* {$form->reason->label()}")->markdown();
        });
    }

    private function addMessageSection(SlackMessage $message, ContactFormData $form): void
    {
        $truncatedMessage = \mb_strlen($form->message) > 500
            ? \mb_substr($form->message, 0, 497) . '...'
            : $form->message;

        $message->sectionBlock(static function (SectionBlock $block) use ($truncatedMessage): void {
            $block->text("*Message:*\n{$truncatedMessage}")->markdown();
        });
    }

    private function addProductSection(SlackMessage $message): void
    {
        $product = $this->submission->product;
        if ($product === null) {
            return;
        }

        $message->sectionBlock(static function (SectionBlock $block) use ($product): void {
            $block->text(self::formatProductText($product))->markdown();
        });
    }

    private static function formatProductText(SelectedProduct $product): string
    {
        $identifier = $product->sku !== null
            ? "ID {$product->productId->value} (SKU: {$product->sku})"
            : "ID {$product->productId->value}";

        $text = "*Product:*\n{$identifier}";
        if ($product->title !== null) {
            $text .= " - {$product->title}";
        }

        return $text;
    }

    private function addMetadataSection(SlackMessage $message, ContactFormData $form): void
    {
        $metadata = [];
        if ($form->orderNumber !== null) {
            $metadata[] = "*Order:* {$form->orderNumber}";
        }
        if ($form->deliveryPostcode !== null) {
            $metadata[] = "*Postcode:* {$form->deliveryPostcode}";
        }

        if ($metadata !== []) {
            $message->sectionBlock(static function (SectionBlock $block) use ($metadata): void {
                $block->text(\implode('  |  ', $metadata))->markdown();
            });
        }
    }

    private function addEmailValiditySection(SlackMessage $message): void
    {
        if ($this->emailValid !== false) {
            return;
        }

        $message->sectionBlock(static function (SectionBlock $block): void {
            $block->text('⚠️ *Email Valid:* No (failed RFC/DNS check)')->markdown();
        });
    }

    private function addErrorSection(SlackMessage $message): void
    {
        $message
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text("*Error:*\n```{$this->errorMessage}```")->markdown();
            })
            ->contextBlock(function (ContextBlock $block): void {
                $block->text($this->buildContextText());
            });
    }

    private function buildContextText(): string
    {
        $contextParts = ["Submission ID: {$this->submissionId}"];

        if ($this->submission->submittedAt !== null) {
            $submittedTime = $this->submission->submittedAt->format('g:ia');
            $submittedDate = $this->submission->submittedAt->format('j M');
            $contextParts[] = "Submitted: {$submittedDate} at {$submittedTime}";
        }

        return \implode('  •  ', $contextParts);
    }
}
