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
        $parts = [
            $submission->form->message,
            "\n---",
            ...$this->buildProductAndMetadataSections($submission),
        ];

        return \implode("\n\n", \array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function buildProductAndMetadataSections(ContactSubmission $submission): array
    {
        $sections = [];

        if ($submission->product !== null) {
            $sections[] = $this->formatProduct($submission->product);
        }

        $metadata = $this->buildMetadata($submission);
        if ($metadata !== []) {
            $sections[] = \implode("\n", $metadata);
        }

        return $sections;
    }

    private function formatProduct(SelectedProduct $product): string
    {
        $lines = \array_filter([
            $this->formatProductIdentifier($product),
            $product->price !== null ? "Price: {$product->price}" : null,
            $product->quantity !== null ? "Quantity: {$product->quantity}" : null,
            $product->url !== null ? "URL: {$product->url}" : null,
        ], static fn(?string $line): bool => $line !== null);

        return \implode("\n", $lines);
    }

    private function formatProductIdentifier(SelectedProduct $product): string
    {
        $line = "Product ID: {$product->productId->value}";
        if ($product->sku !== null) {
            $line .= " (SKU: {$product->sku})";
        }
        if ($product->title !== null) {
            $line .= " - {$product->title}";
        }

        return $line;
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
