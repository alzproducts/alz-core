<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Transformers;

use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\CustomerService\ValueObjects\Tag;

/**
 * Transforms a ContactSubmission into a HelpScout conversation command.
 *
 * Handles:
 * - Body formatting with product/customer details
 * - Tag determination based on contact reason
 * - Fixed conversation settings (mailbox, type, status)
 *
 * Excludes from body: IP address, GCLID, UTM params, user agent, page URL, referrer
 */
final readonly class ContactSubmissionToConversationCommandTransformer
{
    private const string TAG_WEB_FORM = 'web-form';

    public function transform(ContactSubmission $submission): CreateCustomerConversationCommand
    {
        return new CreateCustomerConversationCommand(
            email: $submission->form->email,
            name: $submission->form->name,
            subject: $submission->helpScoutSubject(),
            body: $this->buildBody($submission),
            mailbox: Mailbox::Support,
            type: ConversationType::Email,
            status: ConversationStatus::Active,
            phone: $submission->form->phone,
            tags: $this->buildTags($submission),
        );
    }

    private function buildBody(ContactSubmission $submission): string
    {
        $parts = [];

        // Customer's message first
        $parts[] = $submission->form->message;

        // Separator before metadata
        $parts[] = "\n---";

        // Product details if provided
        if ($submission->product !== null) {
            $parts[] = $this->formatProduct($submission->product);
        }

        // Customer metadata
        $metadata = $this->buildMetadata($submission);
        if ($metadata !== []) {
            $parts[] = \implode("\n", $metadata);
        }

        return \implode("\n\n", \array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private function formatProduct(SelectedProduct $product): string
    {
        $lines = [];

        // Build product identifier line with productId and optionally SKU
        $productLine = "Product ID: {$product->productId->value}";
        if ($product->sku !== null) {
            $productLine .= " (SKU: {$product->sku})";
        }
        if ($product->title !== null) {
            $productLine .= " - {$product->title}";
        }
        $lines[] = $productLine;

        if ($product->price !== null) {
            $lines[] = "Price: {$product->price}";
        }

        if ($product->quantity !== null) {
            $lines[] = "Quantity: {$product->quantity}";
        }

        if ($product->url !== null) {
            $lines[] = "URL: {$product->url}";
        }

        return \implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function buildMetadata(ContactSubmission $submission): array
    {
        $metadata = [];

        if ($submission->form->customerType !== null) {
            $metadata[] = "Customer Type: {$submission->form->customerType->label()}";
        }

        if ($submission->form->orderNumber !== null) {
            $metadata[] = "Order Number: {$submission->form->orderNumber}";
        }

        if ($submission->form->deliveryPostcode !== null) {
            $metadata[] = "Delivery Postcode: {$submission->form->deliveryPostcode}";
        }

        return $metadata;
    }

    /**
     * @return list<Tag>
     */
    private function buildTags(ContactSubmission $submission): array
    {
        return [
            Tag::fromName(self::TAG_WEB_FORM),
            $submission->form->reason->toTag(),
        ];
    }
}
