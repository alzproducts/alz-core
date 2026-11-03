<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Slack;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Notifications\Slack\ContactFormFailedNotification;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversClass(ContactFormFailedNotification::class)]
final class ContactFormFailedNotificationTest extends TestCase
{
    private const string HERE = '<!here>';

    #[Test]
    public function disguised_link_in_message_is_escaped_everywhere(): void
    {
        $texts = self::allTexts(self::payload(
            form: self::form(message: '<https://phish.example|Reset your password>'),
        ));

        foreach ($texts as $text) {
            self::assertStringNotContainsString('<https://phish.example|', $text);
        }
        self::assertContains("*Message:*\n&lt;https://phish.example|Reset your password&gt;", $texts);
    }

    #[Test]
    public function mention_in_customer_and_metadata_fields_is_escaped_everywhere(): void
    {
        $texts = self::allTexts(self::payload(
            form: self::form(
                name: self::HERE,
                email: self::HERE . '@x.example',
                phone: self::HERE,
                orderNumber: self::HERE,
                deliveryPostcode: self::HERE,
            ),
            product: new SelectedProduct(productId: IntId::fromTrusted(42), title: self::HERE),
        ));

        self::assertNoRawHere($texts);
        self::assertContains('Contact form processing failed for &lt;!here&gt;@x.example', $texts);
        self::assertContains(
            "*Email:* &lt;!here&gt;@x.example\n*Name:* &lt;!here&gt;\n*Phone:* &lt;!here&gt;",
            $texts,
        );
        self::assertContains("*Product:*\nID 42 - &lt;!here&gt;", $texts);
        self::assertContains('*Order:* &lt;!here&gt;  |  *Postcode:* &lt;!here&gt;', $texts);
    }

    #[Test]
    public function mention_in_sku_and_error_text_is_escaped_everywhere(): void
    {
        $texts = self::allTexts(self::payload(
            form: self::form(),
            product: new SelectedProduct(productId: IntId::fromTrusted(42), sku: self::HERE),
            errorMessage: 'HelpScout rejected ' . self::HERE,
        ));

        self::assertNoRawHere($texts);
        self::assertContains("*Product:*\nID 42 (SKU: &lt;!here&gt;)", $texts);
        self::assertContains("*Error:*\n```HelpScout rejected &lt;!here&gt;```", $texts);
    }

    #[Test]
    public function keeps_formatting_for_ordinary_input(): void
    {
        $texts = self::allTexts(self::payload(
            form: self::form(),
            product: new SelectedProduct(productId: IntId::fromTrusted(42), sku: 'ABC-1', title: 'Oak Table'),
            errorMessage: 'Timeout',
        ));

        self::assertContains('Contact form processing failed for jane@example.com', $texts);
        self::assertContains("*Email:* jane@example.com\n*Name:* Jane Smith", $texts);
        self::assertContains("*Message:*\nHello there", $texts);
        self::assertContains("*Product:*\nID 42 (SKU: ABC-1) - Oak Table", $texts);
        self::assertContains("*Error:*\n```Timeout```", $texts);
    }

    /**
     * @param list<string> $texts
     */
    private static function assertNoRawHere(array $texts): void
    {
        foreach ($texts as $text) {
            self::assertStringNotContainsString(self::HERE, $text);
        }
    }

    private static function form(
        string $name = 'Jane Smith',
        string $email = 'jane@example.com',
        string $message = 'Hello there',
        ?string $phone = null,
        ?string $orderNumber = null,
        ?string $deliveryPostcode = null,
    ): ContactFormData {
        return new ContactFormData(
            name: $name,
            email: $email,
            reason: ContactReason::Other,
            message: $message,
            phone: $phone,
            orderNumber: $orderNumber,
            deliveryPostcode: $deliveryPostcode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(
        ContactFormData $form,
        ?SelectedProduct $product = null,
        string $errorMessage = 'Processing failed',
    ): array {
        $submission = new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
                ipAddress: '127.0.0.1',
            ),
            product: $product,
        );

        return (new ContactFormFailedNotification($submission, 'submission-uuid-123', $errorMessage))
            ->toSlack(new stdClass())
            ->toArray();
    }

    /**
     * Fallback text plus every `text` string in the blocks, at any depth.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function allTexts(array $payload): array
    {
        $texts = [];
        \array_walk_recursive($payload, static function (mixed $value, int|string $key) use (&$texts): void {
            if ($key === 'text' && \is_string($value)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }
}
