<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\Enums;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\CustomerService\ValueObjects\Tag;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ContactReason Enum Unit Tests.
 *
 * Tests the business logic methods that drive conditional field visibility
 * and HelpScout routing. Critical for correct form behavior.
 */
#[CoversClass(ContactReason::class)]
final class ContactReasonTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | label() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('labelProvider')]
    public function it_returns_correct_label_for_each_case(ContactReason $reason, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $reason->label());
    }

    /**
     * @return array<string, array{ContactReason, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'ProductInformation returns Product Information' => [ContactReason::ProductInformation, 'Product Information'],
            'CheckoutPayment returns Checkout/Payment' => [ContactReason::CheckoutPayment, 'Checkout/Payment'],
            'QuotationRequest returns Quotation Request' => [ContactReason::QuotationRequest, 'Quotation Request'],
            'MyOrderDelivery returns My Order - Delivery' => [ContactReason::MyOrderDelivery, 'My Order - Delivery'],
            'MyOrderReturns returns My Order - Returns' => [ContactReason::MyOrderReturns, 'My Order - Returns'],
            'MyOrderTechnicalSupport returns My Order - Technical Support' => [ContactReason::MyOrderTechnicalSupport, 'My Order - Technical Support'],
            'MyOrderOtherQuery returns My Order - Other Query' => [ContactReason::MyOrderOtherQuery, 'My Order - Other Query'],
            'Marketing returns Marketing' => [ContactReason::Marketing, 'Marketing'],
            'Other returns Other' => [ContactReason::Other, 'Other'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | isOrderRelated() Tests - CRITICAL BUSINESS LOGIC
    |--------------------------------------------------------------------------
    | This determines which form fields are shown. MyOrder* cases require
    | order number and show "recently ordered" instead of "recently viewed".
    */

    #[Test]
    #[DataProvider('orderRelatedProvider')]
    public function it_correctly_identifies_order_related_reasons(ContactReason $reason, bool $expectedOrderRelated): void
    {
        self::assertSame($expectedOrderRelated, $reason->isOrderRelated());
    }

    /**
     * @return array<string, array{ContactReason, bool}>
     */
    public static function orderRelatedProvider(): array
    {
        return [
            // Order-related (require order number)
            'MyOrderDelivery is order-related' => [ContactReason::MyOrderDelivery, true],
            'MyOrderReturns is order-related' => [ContactReason::MyOrderReturns, true],
            'MyOrderTechnicalSupport is order-related' => [ContactReason::MyOrderTechnicalSupport, true],
            'MyOrderOtherQuery is order-related' => [ContactReason::MyOrderOtherQuery, true],
            // Not order-related
            'ProductInformation is NOT order-related' => [ContactReason::ProductInformation, false],
            'CheckoutPayment is NOT order-related' => [ContactReason::CheckoutPayment, false],
            'QuotationRequest is NOT order-related' => [ContactReason::QuotationRequest, false],
            'Marketing is NOT order-related' => [ContactReason::Marketing, false],
            'Other is NOT order-related' => [ContactReason::Other, false],
        ];
    }

    #[Test]
    public function exactly_four_reasons_are_order_related(): void
    {
        $orderRelatedCount = 0;
        foreach (ContactReason::cases() as $reason) {
            if ($reason->isOrderRelated()) {
                $orderRelatedCount++;
            }
        }

        self::assertSame(4, $orderRelatedCount, 'Expected exactly 4 order-related reasons (MyOrder* cases)');
    }

    /*
    |--------------------------------------------------------------------------
    | fromLabel() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validLabelProvider')]
    public function it_creates_from_valid_label(string $label, ContactReason $expectedReason): void
    {
        self::assertSame($expectedReason, ContactReason::fromLabel($label));
    }

    /**
     * @return array<string, array{string, ContactReason}>
     */
    public static function validLabelProvider(): array
    {
        return [
            'Product Information' => ['Product Information', ContactReason::ProductInformation],
            'Checkout/Payment' => ['Checkout/Payment', ContactReason::CheckoutPayment],
            'Quotation Request' => ['Quotation Request', ContactReason::QuotationRequest],
            'My Order - Delivery' => ['My Order - Delivery', ContactReason::MyOrderDelivery],
            'My Order - Returns' => ['My Order - Returns', ContactReason::MyOrderReturns],
            'My Order - Technical Support' => ['My Order - Technical Support', ContactReason::MyOrderTechnicalSupport],
            'My Order - Other Query' => ['My Order - Other Query', ContactReason::MyOrderOtherQuery],
            'Marketing' => ['Marketing', ContactReason::Marketing],
            'Other' => ['Other', ContactReason::Other],
        ];
    }

    #[Test]
    public function it_throws_for_invalid_label(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ContactReason::fromLabel('Invalid Reason');
    }

    #[Test]
    public function fromLabel_is_case_sensitive(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ContactReason::fromLabel('product information'); // lowercase
    }

    /*
    |--------------------------------------------------------------------------
    | toTag() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('tagProvider')]
    public function it_returns_correct_tag_for_each_case(ContactReason $reason, string $expectedTagName): void
    {
        $tag = $reason->toTag();

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame($expectedTagName, $tag->name);
    }

    /**
     * @return array<string, array{ContactReason, string}>
     */
    public static function tagProvider(): array
    {
        return [
            'ProductInformation → product-enquiry' => [ContactReason::ProductInformation, 'product-enquiry'],
            'CheckoutPayment → checkout-payment' => [ContactReason::CheckoutPayment, 'checkout-payment'],
            'QuotationRequest → quote-request' => [ContactReason::QuotationRequest, 'quote-request'],
            'MyOrderDelivery → order-delivery' => [ContactReason::MyOrderDelivery, 'order-delivery'],
            'MyOrderReturns → order-returns' => [ContactReason::MyOrderReturns, 'order-returns'],
            'MyOrderTechnicalSupport → order-support' => [ContactReason::MyOrderTechnicalSupport, 'order-support'],
            'MyOrderOtherQuery → order-query' => [ContactReason::MyOrderOtherQuery, 'order-query'],
            'Marketing → marketing' => [ContactReason::Marketing, 'marketing'],
            'Other → general-enquiry' => [ContactReason::Other, 'general-enquiry'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_nine_cases(): void
    {
        self::assertCount(9, ContactReason::cases());
    }

    #[Test]
    public function all_cases_have_unique_labels(): void
    {
        $labels = \array_map(
            static fn(ContactReason $r): string => $r->label(),
            ContactReason::cases(),
        );

        self::assertSame($labels, \array_unique($labels), 'All labels must be unique');
    }

    #[Test]
    public function all_cases_have_unique_tags(): void
    {
        $tagNames = \array_map(
            static fn(ContactReason $r): string => $r->toTag()->name,
            ContactReason::cases(),
        );

        self::assertSame($tagNames, \array_unique($tagNames), 'All tag names must be unique');
    }
}
