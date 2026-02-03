<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ContactSubmission\Transformers;

use App\Application\ContactSubmission\Transformers\ContactSubmissionToConversationCommandTransformer;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ContactSubmissionToConversationCommandTransformer Unit Tests.
 *
 * Tests the transformation from ContactSubmission to HelpScout conversation command.
 * Critical for correct ticket formatting and categorization.
 */
#[CoversClass(ContactSubmissionToConversationCommandTransformer::class)]
final class ContactSubmissionToConversationCommandTransformerTest extends TestCase
{
    private ContactSubmissionToConversationCommandTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new ContactSubmissionToConversationCommandTransformer();
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Command Field Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_returns_correct_email_from_form(): void
    {
        $submission = $this->createSubmission(email: 'customer@example.com');

        $command = $this->transformer->transform($submission);

        self::assertSame('customer@example.com', $command->email);
    }

    #[Test]
    public function transform_returns_correct_name_from_form(): void
    {
        $submission = $this->createSubmission(name: 'John Smith');

        $command = $this->transformer->transform($submission);

        self::assertSame('John Smith', $command->name);
    }

    #[Test]
    public function transform_returns_subject_from_helpScoutSubject(): void
    {
        $submission = $this->createSubmission(reason: ContactReason::ProductInformation);

        $command = $this->transformer->transform($submission);

        self::assertSame('[Product Information] Contact Us Form', $command->subject);
    }

    #[Test]
    public function transform_uses_support_mailbox(): void
    {
        $submission = $this->createSubmission();

        $command = $this->transformer->transform($submission);

        self::assertSame(Mailbox::Support, $command->mailbox);
    }

    #[Test]
    public function transform_uses_email_type(): void
    {
        $submission = $this->createSubmission();

        $command = $this->transformer->transform($submission);

        self::assertSame(ConversationType::Email, $command->type);
    }

    #[Test]
    public function transform_uses_active_status(): void
    {
        $submission = $this->createSubmission();

        $command = $this->transformer->transform($submission);

        self::assertSame(ConversationStatus::Active, $command->status);
    }

    #[Test]
    public function transform_includes_phone_when_present(): void
    {
        $submission = $this->createSubmission(phone: '+44 7911 123456');

        $command = $this->transformer->transform($submission);

        self::assertSame('+44 7911 123456', $command->phone);
    }

    #[Test]
    public function transform_phone_is_null_when_not_present(): void
    {
        $submission = $this->createSubmission(phone: null);

        $command = $this->transformer->transform($submission);

        self::assertNull($command->phone);
    }

    /*
    |--------------------------------------------------------------------------
    | buildBody() - Message Formatting Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function body_starts_with_customer_message(): void
    {
        $submission = $this->createSubmission(message: 'I need help with my order.');

        $command = $this->transformer->transform($submission);

        self::assertStringStartsWith('I need help with my order.', $command->body);
    }

    #[Test]
    public function body_includes_separator_after_message(): void
    {
        $submission = $this->createSubmission(message: 'Test message');

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString("\n---", $command->body);
    }

    #[Test]
    public function body_includes_product_sku_when_product_present(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123');
        $submission = $this->createSubmission(product: $product);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Product: ABC-123', $command->body);
    }

    #[Test]
    public function body_includes_product_title_when_present(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123', title: 'Premium Walker');
        $submission = $this->createSubmission(product: $product);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Product: ABC-123 - Premium Walker', $command->body);
    }

    #[Test]
    public function body_includes_product_price_when_present(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123', price: '£149.99');
        $submission = $this->createSubmission(product: $product);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Price: £149.99', $command->body);
    }

    #[Test]
    public function body_includes_product_quantity_when_present(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123', quantity: 5);
        $submission = $this->createSubmission(product: $product);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Quantity: 5', $command->body);
    }

    #[Test]
    public function body_includes_product_url_when_present(): void
    {
        $product = new SelectedProduct(sku: 'ABC-123', url: 'https://example.com/product');
        $submission = $this->createSubmission(product: $product);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('URL: https://example.com/product', $command->body);
    }

    #[Test]
    public function body_excludes_product_section_when_no_product(): void
    {
        $submission = $this->createSubmission(product: null);

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('Product:', $command->body);
    }

    #[Test]
    public function body_includes_customer_type_label_when_present(): void
    {
        $submission = $this->createSubmission(customerType: CustomerType::Nhs);

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Customer Type: NHS', $command->body);
    }

    #[Test]
    public function body_includes_order_number_when_present(): void
    {
        $submission = $this->createSubmission(orderNumber: 'ORD-12345');

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Order Number: ORD-12345', $command->body);
    }

    #[Test]
    public function body_includes_delivery_postcode_when_present(): void
    {
        $submission = $this->createSubmission(deliveryPostcode: 'SW1A 1AA');

        $command = $this->transformer->transform($submission);

        self::assertStringContainsString('Delivery Postcode: SW1A 1AA', $command->body);
    }

    /*
    |--------------------------------------------------------------------------
    | buildBody() - PII Exclusion Tests (SECURITY)
    |--------------------------------------------------------------------------
    | The body should NOT include PII that isn't necessary for support.
    */

    #[Test]
    public function body_excludes_ip_address(): void
    {
        $submission = $this->createSubmission(ipAddress: '192.168.1.100');

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('192.168.1.100', $command->body);
        self::assertStringNotContainsString('IP', $command->body);
    }

    #[Test]
    public function body_excludes_gclid(): void
    {
        $submission = $this->createSubmissionWithAttribution(gclid: 'CjwKCAjw');

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('CjwKCAjw', $command->body);
        self::assertStringNotContainsString('gclid', $command->body);
    }

    #[Test]
    public function body_excludes_utm_parameters(): void
    {
        $submission = $this->createSubmissionWithAttribution(
            utmSource: 'google',
            utmCampaign: 'spring_sale',
        );

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('utm', $command->body);
        self::assertStringNotContainsString('google', $command->body);
        self::assertStringNotContainsString('spring_sale', $command->body);
    }

    #[Test]
    public function body_excludes_user_agent(): void
    {
        $submission = $this->createSubmission(userAgent: 'Mozilla/5.0 Chrome/120');

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('Mozilla', $command->body);
        self::assertStringNotContainsString('Chrome', $command->body);
        self::assertStringNotContainsString('User Agent', $command->body);
    }

    #[Test]
    public function body_excludes_page_url(): void
    {
        $submission = $this->createSubmission(pageUrl: 'https://alzproducts.co.uk/contact');

        $command = $this->transformer->transform($submission);

        // Should not contain page URL (different from product URL)
        self::assertStringNotContainsString('alzproducts.co.uk/contact', $command->body);
    }

    #[Test]
    public function body_excludes_referrer_url(): void
    {
        $submission = $this->createSubmission(referrerUrl: 'https://google.com/search');

        $command = $this->transformer->transform($submission);

        self::assertStringNotContainsString('google.com/search', $command->body);
        self::assertStringNotContainsString('Referrer', $command->body);
    }

    /*
    |--------------------------------------------------------------------------
    | buildTags() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function tags_always_include_web_form(): void
    {
        $submission = $this->createSubmission();

        $command = $this->transformer->transform($submission);

        $tagNames = \array_map(static fn($tag) => $tag->name, $command->tags);
        self::assertContains('web-form', $tagNames);
    }

    #[Test]
    public function tags_include_reason_tag(): void
    {
        $submission = $this->createSubmission(reason: ContactReason::QuotationRequest);

        $command = $this->transformer->transform($submission);

        $tagNames = \array_map(static fn($tag) => $tag->name, $command->tags);
        self::assertContains('quote-request', $tagNames);
    }

    #[Test]
    public function tags_has_exactly_two_tags(): void
    {
        $submission = $this->createSubmission();

        $command = $this->transformer->transform($submission);

        self::assertCount(2, $command->tags);
    }

    /*
    |--------------------------------------------------------------------------
    | Integration / Full Transform Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_creates_complete_command_with_all_fields(): void
    {
        $product = new SelectedProduct(
            sku: 'FULL-TEST',
            title: 'Complete Test Product',
            price: '£99.99',
            url: 'https://example.com/product/full-test',
            source: ProductSource::RecentlyViewed,
            quantity: 3,
        );

        $submission = $this->createSubmission(
            name: 'Test Customer',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Where is my delivery?',
            phone: '+44 123 456 7890',
            customerType: CustomerType::CareHome,
            orderNumber: 'ORD-999',
            deliveryPostcode: 'AB1 2CD',
            product: $product,
        );

        $command = $this->transformer->transform($submission);

        // Basic fields
        self::assertSame('test@example.com', $command->email);
        self::assertSame('Test Customer', $command->name);
        self::assertSame('[My Order - Delivery] Contact Us Form', $command->subject);
        self::assertSame('+44 123 456 7890', $command->phone);

        // Body contains all expected sections
        self::assertStringContainsString('Where is my delivery?', $command->body);
        self::assertStringContainsString('Product: FULL-TEST - Complete Test Product', $command->body);
        self::assertStringContainsString('Price: £99.99', $command->body);
        self::assertStringContainsString('Quantity: 3', $command->body);
        self::assertStringContainsString('URL: https://example.com/product/full-test', $command->body);
        self::assertStringContainsString('Customer Type: Care Home', $command->body);
        self::assertStringContainsString('Order Number: ORD-999', $command->body);
        self::assertStringContainsString('Delivery Postcode: AB1 2CD', $command->body);

        // Tags
        $tagNames = \array_map(static fn($tag) => $tag->name, $command->tags);
        self::assertSame(['web-form', 'order-delivery'], $tagNames);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createSubmission(
        string $name = 'Test User',
        string $email = 'test@example.com',
        ContactReason $reason = ContactReason::Other,
        string $message = 'Test message',
        ?string $phone = null,
        ?CustomerType $customerType = null,
        ?string $orderNumber = null,
        ?string $deliveryPostcode = null,
        ?SelectedProduct $product = null,
        string $ipAddress = '127.0.0.1',
        ?string $pageUrl = null,
        ?string $referrerUrl = null,
        ?string $userAgent = null,
    ): ContactSubmission {
        $form = new ContactFormData(
            name: $name,
            email: $email,
            reason: $reason,
            message: $message,
            phone: $phone,
            customerType: $customerType,
            orderNumber: $orderNumber,
            deliveryPostcode: $deliveryPostcode,
        );

        $context = new SubmissionContext(
            clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
            ipAddress: $ipAddress,
            pageUrl: $pageUrl,
            referrerUrl: $referrerUrl,
            userAgent: $userAgent,
        );

        return new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: $context,
            product: $product,
        );
    }

    private function createSubmissionWithAttribution(
        ?string $gclid = null,
        ?string $utmSource = null,
        ?string $utmMedium = null,
        ?string $utmCampaign = null,
        ?string $utmContent = null,
        ?string $utmTerm = null,
    ): ContactSubmission {
        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: 'Test message',
        );

        $attribution = new MarketingAttribution(
            gclid: $gclid,
            utmSource: $utmSource,
            utmMedium: $utmMedium,
            utmCampaign: $utmCampaign,
            utmContent: $utmContent,
            utmTerm: $utmTerm,
        );

        $context = new SubmissionContext(
            clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
            ipAddress: '127.0.0.1',
        );

        return new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: $attribution,
            context: $context,
        );
    }
}
