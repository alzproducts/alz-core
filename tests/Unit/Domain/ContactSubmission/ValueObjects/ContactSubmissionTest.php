<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ContactSubmission Aggregate Root Unit Tests.
 *
 * Tests the aggregate that combines all value objects.
 * helpScoutSubject() formats the ticket subject line.
 */
#[CoversClass(ContactSubmission::class)]
final class ContactSubmissionTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_required_value_objects(): void
    {
        $form = $this->createFormData();
        $consent = ConsentStatus::denied();
        $attribution = MarketingAttribution::empty();
        $context = $this->createContext();

        $submission = new ContactSubmission(
            form: $form,
            consent: $consent,
            attribution: $attribution,
            context: $context,
        );

        self::assertSame($form, $submission->form);
        self::assertSame($consent, $submission->consent);
        self::assertSame($attribution, $submission->attribution);
        self::assertSame($context, $submission->context);
        self::assertNull($submission->product);
        self::assertNull($submission->shopwiredCustomerId);
    }

    #[Test]
    public function it_creates_with_all_value_objects(): void
    {
        $form = $this->createFormData();
        $consent = new ConsentStatus(true, true, true, true);
        $attribution = new MarketingAttribution(gclid: 'test-gclid');
        $context = $this->createContext();
        $product = new SelectedProduct(productId: IntId::from(12345), sku: 'TEST-SKU', source: ProductSource::RecentlyViewed);

        $submission = new ContactSubmission(
            form: $form,
            consent: $consent,
            attribution: $attribution,
            context: $context,
            product: $product,
            shopwiredCustomerId: 'SW-CUST-123',
        );

        self::assertSame($form, $submission->form);
        self::assertSame($consent, $submission->consent);
        self::assertSame($attribution, $submission->attribution);
        self::assertSame($context, $submission->context);
        self::assertSame($product, $submission->product);
        self::assertSame('SW-CUST-123', $submission->shopwiredCustomerId);
    }

    /*
    |--------------------------------------------------------------------------
    | helpScoutSubject() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('subjectProvider')]
    public function it_formats_helpscout_subject_correctly(ContactReason $reason, string $expectedSubject): void
    {
        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: $reason,
            message: 'Test message',
        );

        $submission = new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: $this->createContext(),
        );

        self::assertSame($expectedSubject, $submission->helpScoutSubject());
    }

    /**
     * @return array<string, array{ContactReason, string}>
     */
    public static function subjectProvider(): array
    {
        return [
            'ProductInformation' => [
                ContactReason::ProductInformation,
                '[Product Information] Contact Us Form',
            ],
            'CheckoutPayment' => [
                ContactReason::CheckoutPayment,
                '[Checkout/Payment] Contact Us Form',
            ],
            'QuotationRequest' => [
                ContactReason::QuotationRequest,
                '[Quotation Request] Contact Us Form',
            ],
            'MyOrderDelivery' => [
                ContactReason::MyOrderDelivery,
                '[My Order - Delivery] Contact Us Form',
            ],
            'MyOrderReturns' => [
                ContactReason::MyOrderReturns,
                '[My Order - Returns] Contact Us Form',
            ],
            'MyOrderTechnicalSupport' => [
                ContactReason::MyOrderTechnicalSupport,
                '[My Order - Technical Support] Contact Us Form',
            ],
            'MyOrderOtherQuery' => [
                ContactReason::MyOrderOtherQuery,
                '[My Order - Other Query] Contact Us Form',
            ],
            'Marketing' => [
                ContactReason::Marketing,
                '[Marketing] Contact Us Form',
            ],
            'Other' => [
                ContactReason::Other,
                '[Other] Contact Us Form',
            ],
        ];
    }

    #[Test]
    public function helpScoutSubject_does_not_include_order_number(): void
    {
        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Where is my order?',
            orderNumber: 'ORD-12345',
        );

        $submission = new ContactSubmission(
            form: $form,
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: $this->createContext(),
        );

        $subject = $submission->helpScoutSubject();

        // Order number should NOT be in the subject (security: unvalidated customer input)
        self::assertStringNotContainsString('ORD-12345', $subject);
        self::assertSame('[My Order - Delivery] Contact Us Form', $subject);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createFormData(): ContactFormData
    {
        return new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::ProductInformation,
            message: 'Test message',
        );
    }

    private function createContext(): SubmissionContext
    {
        return new SubmissionContext(
            clientTimestamp: new DateTimeImmutable('2024-01-15 10:00:00'),
            ipAddress: '192.168.1.1',
        );
    }
}
