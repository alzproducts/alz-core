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
 * Body format: HTML with a structured contact-details header above the customer message.
 * HelpScout auto-detects HTML in the `text` field — no SDK configuration needed.
 *
 * Included in body (support-relevant):
 * - Name, Email, Reason, Phone (contact header)
 * - Customer message
 * - Product details (title as hyperlink, SKU, price, quantity)
 * - Customer Type, Order Number, Delivery Postcode (metadata)
 *
 * Excluded from body (tracking / PII):
 * - IP address, User Agent, Page URL, Referrer URL
 * - Click IDs (gclid, gclsrc, wbraid, gbraid, msclkid, fbclid)
 * - UTM parameters (source, medium, campaign, content, term)
 *
 * Security: all user-provided values are htmlspecialchars()-escaped to prevent
 * XSS in the HelpScout agent UI.
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
            $this->buildContactHeader($submission),
            '<hr>',
            self::e($submission->form->message),
            ...$this->buildProductAndMetadataSections($submission),
        ];

        return \implode("\n\n", $parts);
    }

    private function buildContactHeader(ContactSubmission $submission): string
    {
        $lines = [
            '<strong>Name:</strong> ' . self::e($submission->form->name),
            '<strong>Email:</strong> ' . self::e($submission->form->email),
            '<strong>Reason:</strong> ' . self::e($submission->form->reason->label()),
        ];

        if ($submission->form->phone !== null) {
            $lines[] = '<strong>Phone:</strong> ' . self::e($submission->form->phone);
        }

        return \implode("<br>\n", $lines);
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
            $sections[] = \implode("<br>\n", $metadata);
        }

        if ($sections === []) {
            return [];
        }

        return ['<hr>', ...$sections];
    }

    private function formatProduct(SelectedProduct $product): string
    {
        $lines = \array_filter([
            $this->formatProductIdentifier($product),
            $product->price !== null ? '<strong>Price:</strong> ' . self::e($product->price) : null,
            $product->quantity !== null ? "<strong>Quantity:</strong> {$product->quantity}" : null,
        ], static fn(?string $line): bool => $line !== null);

        return \implode("<br>\n", $lines);
    }

    private function formatProductIdentifier(SelectedProduct $product): string
    {
        $productName = self::buildProductName($product);

        if ($productName === null) {
            return $product->sku !== null
                ? '<strong>Product:</strong> ' . self::e($product->sku)
                : '<strong>Product:</strong> #' . $product->productId->value;
        }

        $line = '<strong>Product:</strong> ' . $productName;
        if ($product->sku !== null) {
            $line .= ' - ' . self::e($product->sku);
        }

        return $line;
    }

    private static function buildProductName(SelectedProduct $product): ?string
    {
        if ($product->title === null) {
            return null;
        }

        $escapedTitle = self::e($product->title);

        return $product->url !== null
            ? '<a href="' . self::e($product->url) . '">' . $escapedTitle . '</a>'
            : $escapedTitle;
    }

    /**
     * @return list<string>
     */
    private function buildMetadata(ContactSubmission $submission): array
    {
        $metadata = [];

        if ($submission->form->customerType !== null) {
            $metadata[] = '<strong>Customer Type:</strong> ' . self::e($submission->form->customerType->label());
        }

        if ($submission->form->orderNumber !== null) {
            $metadata[] = '<strong>Order Number:</strong> ' . self::e($submission->form->orderNumber);
        }

        if ($submission->form->deliveryPostcode !== null) {
            $metadata[] = '<strong>Delivery Postcode:</strong> ' . self::e($submission->form->deliveryPostcode);
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

    private static function e(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
