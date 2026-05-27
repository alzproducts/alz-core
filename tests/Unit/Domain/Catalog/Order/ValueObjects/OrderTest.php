<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order Value Object Unit Tests.
 *
 * Tests business logic methods only - PHPStan handles type/structure validation.
 */
#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrder(array $overrides = []): Order
    {
        $defaults = [
            'id' => 98765,
            'reference' => 12345,
            'orderPlacedAt' => new DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            'total' => 110.50,
            'subTotalNet' => 100.00,
            'shippingTotalNet' => 10.50,
            'originalShippingTotalNet' => 10.50,
            'paymentMethod' => PaymentMethod::Card,
            'comments' => '',
            'marketing' => true,
            'hasVatRelief' => false,
            'isArchived' => false,
            'isAnonymized' => false,
            'lineItemVatCalculation' => false,
            'status' => new OrderStatus(1, OrderStatusType::Completed, 'shipped', 0),
            'customer' => new OrderCustomer(99, 1, null, []),
            'shipping' => new OrderShipping(id: 1, name: 'Standard', chargeNet: 10.50, vatRate: 20.0),
            'billingAddress' => $this->createOrderAddress(),
            'shippingAddress' => $this->createOrderAddress(),
            'preOrderStatus' => PreOrderStatus::None,
            'taxValue' => null,
            'trackingUrl' => null,
            'invoiceUrl' => null,
            'transactionId' => null,
            'deliveryDate' => null,
            'discounts' => [],
            'refunds' => [],
            'adminComments' => [],
            'products' => null,
            'customFields' => null,
        ];

        return new Order(...\array_merge($defaults, $overrides));
    }

    private function createOrderAddress(): OrderAddress
    {
        return new OrderAddress(
            name: 'John Doe',
            emailAddress: 'john@example.com',
            telephone: '01234567890',
            companyName: '',
            addressLine1: '123 Test St',
            addressLine2: '',
            addressLine3: null,
            city: 'London',
            province: '',
            state: null,
            postcode: 'SW1A 1AA',
            country: 'United Kingdom',
            countryId: 1,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | hasProducts() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_products_returns_false_when_products_is_null(): void
    {
        $order = $this->createOrder(['products' => null]);

        $this->assertFalse($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_is_empty_array(): void
    {
        $order = $this->createOrder(['products' => []]);

        $this->assertTrue($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_array_has_items(): void
    {
        $products = [
            new OrderProduct(
                id: 1,
                orderExternalId: 98765,
                title: 'Test',
                sku: 'SKU-1',
                price: 10.0,
                priceVat: 2.0,
                total: 10.0,
                totalVat: 2.0,
                originalPrice: 10.0,
                costPrice: 5.0,
                quantity: 1,
                vatRate: 20.0,
                comments: '',
                isPreorder: false,
            ),
        ];
        $order = $this->createOrder(['products' => $products]);

        $this->assertTrue($order->hasProducts());
    }

    /*
    |--------------------------------------------------------------------------
    | hasDiscounts() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_discounts_returns_false_when_discounts_is_empty(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertFalse($order->hasDiscounts());
    }

    #[Test]
    public function has_discounts_returns_true_when_discounts_exist(): void
    {
        $discounts = [new OrderDiscount('VOUCHER', 10.0, null, null, null, null)];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertTrue($order->hasDiscounts());
    }

    /*
    |--------------------------------------------------------------------------
    | totalDiscountValue() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_discount_value_returns_zero_when_no_discounts(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertSame(0.0, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_returns_zero_when_discounts_null(): void
    {
        $order = $this->createOrder(['discounts' => null]);

        $this->assertSame(0.0, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_returns_single_discount_value(): void
    {
        $discounts = [new OrderDiscount('15OFF', 15.75, null, null, null, null)];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(15.75, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_sums_multiple_discounts(): void
    {
        $discounts = [
            new OrderDiscount('VOUCHER', 10.00, null, null, null, null),
            new OrderDiscount('SALE', 5.50, null, null, null, null),
            new OrderDiscount('LOYALTY', 2.25, null, null, null, null),
        ];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(17.75, $order->totalDiscountValue());
    }

    /*
    |--------------------------------------------------------------------------
    | hasRefunds() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_refunds_returns_false_when_refunds_is_empty(): void
    {
        $order = $this->createOrder(['refunds' => []]);

        $this->assertFalse($order->hasRefunds());
    }

    #[Test]
    public function has_refunds_returns_true_when_refunds_exist(): void
    {
        $refunds = [
            new OrderRefund(1, 'Customer return', 15.00, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertTrue($order->hasRefunds());
    }

    /*
    |--------------------------------------------------------------------------
    | totalRefundValue() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_refund_value_returns_zero_when_no_refunds(): void
    {
        $order = $this->createOrder(['refunds' => []]);

        $this->assertSame(0.0, $order->totalRefundValue());
    }

    #[Test]
    public function total_refund_value_returns_zero_when_refunds_null(): void
    {
        $order = $this->createOrder(['refunds' => null]);

        $this->assertSame(0.0, $order->totalRefundValue());
    }

    #[Test]
    public function total_refund_value_returns_single_refund_value(): void
    {
        $refunds = [
            new OrderRefund(1, 'Damaged item', 25.50, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertSame(25.50, $order->totalRefundValue());
    }

    #[Test]
    public function total_refund_value_sums_multiple_refunds(): void
    {
        $refunds = [
            new OrderRefund(1, 'Item 1 return', 10.00, new DateTimeImmutable()),
            new OrderRefund(2, 'Item 2 return', 5.50, new DateTimeImmutable()),
            new OrderRefund(3, 'Shipping refund', 2.25, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertSame(17.75, $order->totalRefundValue());
    }

    /*
    |--------------------------------------------------------------------------
    | hasAdminComments() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_admin_comments_returns_false_when_comments_is_empty(): void
    {
        $order = $this->createOrder(['adminComments' => []]);

        $this->assertFalse($order->hasAdminComments());
    }

    #[Test]
    public function has_admin_comments_returns_true_when_comments_exist(): void
    {
        $comments = [
            new OrderAdminComment(1, 'Customer called about delivery', new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['adminComments' => $comments]);

        $this->assertTrue($order->hasAdminComments());
    }

    /*
    |--------------------------------------------------------------------------
    | extractCustomerReferenceNumber() Tests - Static Extraction Method
    |--------------------------------------------------------------------------
    |
    | Tests the pure function that extracts customer reference numbers from
    | order comments. Covers all edge cases including empty input, delimiter
    | exclusion, case sensitivity, whitespace handling, truncation, and
    | special characters.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Category 1: Empty and Null-like Input
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_returns_null_for_empty_string(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber(''));
    }

    #[Test]
    public function extract_reference_returns_null_for_whitespace_only(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('   '));
    }

    #[Test]
    public function extract_reference_returns_null_for_single_space(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber(' '));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 2: Missing "reference" Keyword
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_returns_null_when_no_reference_keyword(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Please deliver to back door'));
    }

    #[Test]
    public function extract_reference_returns_null_for_partial_keyword_ref(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('ref ABC123'));
    }

    #[Test]
    public function extract_reference_returns_null_for_references_plural(): void
    {
        // "references" without space after doesn't match "reference "
        $this->assertNull(Order::extractCustomerReferenceNumber('references ABC123'));
    }

    #[Test]
    public function extract_reference_returns_null_when_no_space_after_keyword(): void
    {
        // "reference123" doesn't match "reference " (needs trailing space)
        $this->assertNull(Order::extractCustomerReferenceNumber('reference123'));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 3: Case Sensitivity
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('caseSensitivityProvider')]
    public function extract_reference_is_case_insensitive(string $comments, string $expected): void
    {
        $this->assertSame($expected, Order::extractCustomerReferenceNumber($comments));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseSensitivityProvider(): array
    {
        return [
            'title case' => ['Reference ABC123', 'ABC123'],
            'lowercase' => ['reference ABC123', 'ABC123'],
            'uppercase' => ['REFERENCE ABC123', 'ABC123'],
            'mixed case' => ['ReFeReNcE ABC123', 'ABC123'],
            'inverse mixed case' => ['rEFERENCE ABC123', 'ABC123'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Category 4: Delimiter Exclusion (Old Delimiter ':-')
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_returns_null_when_old_delimiter_present(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Reference ABC123 :- Admin note'));
    }

    #[Test]
    public function extract_reference_returns_null_when_old_delimiter_before_reference(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber(':- Note Reference ABC123'));
    }

    #[Test]
    public function extract_reference_returns_null_when_old_delimiter_at_start(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber(':-Reference ABC123'));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 5: Delimiter Exclusion (New Delimiter '*>')
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_returns_null_when_new_delimiter_present(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Reference ABC123 *> Admin note'));
    }

    #[Test]
    public function extract_reference_returns_null_when_new_delimiter_before_reference(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('*> Note Reference ABC123'));
    }

    #[Test]
    public function extract_reference_returns_null_when_both_delimiters_present(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Reference ABC :- note *> more'));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 6: Partial Delimiter Characters (Should NOT Exclude)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_allows_colon_without_hyphen(): void
    {
        $this->assertSame('ABC:123', Order::extractCustomerReferenceNumber('Reference ABC:123'));
    }

    #[Test]
    public function extract_reference_allows_hyphen_without_colon(): void
    {
        $this->assertSame('ABC-123', Order::extractCustomerReferenceNumber('Reference ABC-123'));
    }

    #[Test]
    public function extract_reference_allows_asterisk_without_greater_than(): void
    {
        $this->assertSame('ABC*123', Order::extractCustomerReferenceNumber('Reference ABC*123'));
    }

    #[Test]
    public function extract_reference_allows_greater_than_without_asterisk(): void
    {
        $this->assertSame('ABC>123', Order::extractCustomerReferenceNumber('Reference ABC>123'));
    }

    #[Test]
    public function extract_reference_allows_spaced_colon_hyphen(): void
    {
        // ": -" (space between) is not the delimiter ":-"
        $this->assertSame('ABC : - 123', Order::extractCustomerReferenceNumber('Reference ABC : - 123'));
    }

    #[Test]
    public function extract_reference_allows_spaced_asterisk_greater_than(): void
    {
        // "* >" (space between) is not the delimiter "*>"
        $this->assertSame('ABC * > 123', Order::extractCustomerReferenceNumber('Reference ABC * > 123'));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 7: Newline Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_stops_at_newline(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("Reference ABC123\nmore text"));
    }

    #[Test]
    public function extract_reference_handles_windows_line_endings(): void
    {
        // \r\n: finds \n at position, extracts up to it, trim removes trailing \r
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("Reference ABC123\r\nmore text"));
    }

    #[Test]
    public function extract_reference_returns_all_when_no_newline(): void
    {
        $this->assertSame('ABC123 more text', Order::extractCustomerReferenceNumber('Reference ABC123 more text'));
    }

    #[Test]
    public function extract_reference_handles_multiple_newlines(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("Reference ABC123\nline2\nline3"));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 8: Multiple "Reference" Occurrences
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_uses_first_occurrence(): void
    {
        $this->assertSame(
            'ABC123 Reference XYZ789',
            Order::extractCustomerReferenceNumber('Reference ABC123 Reference XYZ789'),
        );
    }

    #[Test]
    public function extract_reference_first_occurrence_respects_newline(): void
    {
        $this->assertSame(
            'ABC123',
            Order::extractCustomerReferenceNumber("Note: Reference ABC123\nReference XYZ789"),
        );
    }

    #[Test]
    public function extract_reference_finds_first_in_multiline(): void
    {
        $comments = "Some notes\nReference FIRST-123\nReference SECOND-456";
        $this->assertSame('FIRST-123', Order::extractCustomerReferenceNumber($comments));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 9: Reference Position Variations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_at_start_of_string(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber('Reference ABC123'));
    }

    #[Test]
    public function extract_reference_in_middle_of_string(): void
    {
        $this->assertSame('ABC123 end', Order::extractCustomerReferenceNumber('Start text Reference ABC123 end'));
    }

    #[Test]
    public function extract_reference_after_newline(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("Notes here\nReference ABC123"));
    }

    #[Test]
    public function extract_reference_with_preceding_text(): void
    {
        $this->assertSame('PO-2024-001', Order::extractCustomerReferenceNumber('My Reference PO-2024-001'));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 10: Whitespace Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_trims_trailing_whitespace(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber('Reference ABC123   '));
    }

    #[Test]
    public function extract_reference_trims_leading_whitespace_after_keyword(): void
    {
        // "Reference  ABC" → extract " ABC" → trim → "ABC"
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber('Reference  ABC123'));
    }

    #[Test]
    public function extract_reference_handles_multiple_spaces(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber('Reference    ABC123    '));
    }

    #[Test]
    public function extract_reference_trims_tabs(): void
    {
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("Reference \tABC123\t"));
    }

    #[Test]
    public function extract_reference_preserves_internal_whitespace(): void
    {
        // Internal spaces are preserved
        $this->assertSame('ABC 123 XYZ', Order::extractCustomerReferenceNumber('Reference ABC 123 XYZ'));
    }

    #[Test]
    public function extract_reference_preserves_internal_tabs(): void
    {
        $this->assertSame("ABC\t123", Order::extractCustomerReferenceNumber("Reference ABC\t123"));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 11: Special Characters in Reference
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('specialCharactersProvider')]
    public function extract_reference_handles_special_characters(string $comments, string $expected): void
    {
        $this->assertSame($expected, Order::extractCustomerReferenceNumber($comments));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function specialCharactersProvider(): array
    {
        return [
            'hyphen' => ['Reference ABC-123', 'ABC-123'],
            'slash' => ['Reference ABC/123', 'ABC/123'],
            'underscore' => ['Reference ABC_123', 'ABC_123'],
            'dot' => ['Reference ABC.123', 'ABC.123'],
            'hash' => ['Reference ABC#123', 'ABC#123'],
            'spaces within' => ['Reference PO 2024-001', 'PO 2024-001'],
            'parentheses' => ['Reference ABC (2024)', 'ABC (2024)'],
            'ampersand' => ['Reference ABC & Co', 'ABC & Co'],
            'at symbol' => ['Reference order@123', 'order@123'],
            'plus sign' => ['Reference ABC+123', 'ABC+123'],
            'equals sign' => ['Reference ABC=123', 'ABC=123'],
            'square brackets' => ['Reference [ABC-123]', '[ABC-123]'],
            'curly braces' => ['Reference {ABC-123}', '{ABC-123}'],
            'pipe' => ['Reference ABC|123', 'ABC|123'],
            'backslash' => ['Reference ABC\\123', 'ABC\\123'],
            'semicolon' => ['Reference ABC;123', 'ABC;123'],
            'comma' => ['Reference ABC,123', 'ABC,123'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Category 12: Unicode and International Characters
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('unicodeCharactersProvider')]
    public function extract_reference_handles_unicode(string $comments, string $expected): void
    {
        $this->assertSame($expected, Order::extractCustomerReferenceNumber($comments));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unicodeCharactersProvider(): array
    {
        return [
            'pound symbol' => ['Reference £100', '£100'],
            'euro symbol' => ['Reference €200', '€200'],
            'yen symbol' => ['Reference ¥300', '¥300'],
            'accented characters' => ['Reference Référence-123', 'Référence-123'],
            'german umlaut' => ['Reference Büro-456', 'Büro-456'],
            'japanese' => ['Reference 日本語-789', '日本語-789'],
            'chinese' => ['Reference 中文-101', '中文-101'],
            'arabic' => ['Reference عربي-202', 'عربي-202'],
            'emoji' => ['Reference 🎉-303', '🎉-303'],
            'mixed unicode' => ['Reference Café-été-123', 'Café-été-123'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Category 13: Empty Reference Value
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_returns_null_when_only_keyword(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Reference '));
    }

    #[Test]
    public function extract_reference_returns_null_when_keyword_followed_by_newline(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber("Reference \n"));
    }

    #[Test]
    public function extract_reference_returns_null_when_keyword_followed_by_spaces(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber('Reference    '));
    }

    #[Test]
    public function extract_reference_returns_null_when_keyword_followed_by_whitespace_then_newline(): void
    {
        $this->assertNull(Order::extractCustomerReferenceNumber("Reference   \n"));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 14: Truncation (255 Character Limit)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_allows_exactly_255_characters(): void
    {
        $reference255 = \str_repeat('A', 255);
        $this->assertSame($reference255, Order::extractCustomerReferenceNumber("Reference {$reference255}"));
    }

    #[Test]
    public function extract_reference_truncates_at_256_characters(): void
    {
        $reference256 = \str_repeat('B', 256);
        $expected255 = \str_repeat('B', 255);
        $this->assertSame($expected255, Order::extractCustomerReferenceNumber("Reference {$reference256}"));
    }

    #[Test]
    public function extract_reference_truncates_long_string(): void
    {
        $reference300 = \str_repeat('C', 300);
        $expected255 = \str_repeat('C', 255);
        $this->assertSame($expected255, Order::extractCustomerReferenceNumber("Reference {$reference300}"));
    }

    #[Test]
    public function extract_reference_allows_254_characters(): void
    {
        $reference254 = \str_repeat('D', 254);
        $this->assertSame($reference254, Order::extractCustomerReferenceNumber("Reference {$reference254}"));
    }

    #[Test]
    public function extract_reference_truncates_unicode_correctly(): void
    {
        // Each emoji is multiple bytes but 1 character - mb_substr handles correctly
        $reference = \str_repeat('🎉', 260); // 260 emoji characters
        $expected = \str_repeat('🎉', 255); // Should be truncated to 255 characters
        $this->assertSame($expected, Order::extractCustomerReferenceNumber("Reference {$reference}"));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 15: Realistic Multiline Scenarios
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_realistic_delivery_note(): void
    {
        $comments = "Please deliver to back door\nReference PO-2024-001\nThank you";
        $this->assertSame('PO-2024-001', Order::extractCustomerReferenceNumber($comments));
    }

    #[Test]
    public function extract_reference_realistic_purchase_order(): void
    {
        $comments = "Company: Acme Corp\nReference PO/2024/ABC-123\nContact: John Doe";
        $this->assertSame('PO/2024/ABC-123', Order::extractCustomerReferenceNumber($comments));
    }

    #[Test]
    public function extract_reference_realistic_customer_note(): void
    {
        $comments = 'Leave with neighbour if not in. Reference ABC123';
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber($comments));
    }

    #[Test]
    public function extract_reference_realistic_long_reference(): void
    {
        $comments = "Reference INV-2024-01-15-CUSTOMER-ORDER-12345-UK\nAdditional notes here";
        $this->assertSame('INV-2024-01-15-CUSTOMER-ORDER-12345-UK', Order::extractCustomerReferenceNumber($comments));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 16: Boundary and Edge Conditions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function extract_reference_single_character(): void
    {
        $this->assertSame('A', Order::extractCustomerReferenceNumber('Reference A'));
    }

    #[Test]
    public function extract_reference_number_only(): void
    {
        $this->assertSame('12345', Order::extractCustomerReferenceNumber('Reference 12345'));
    }

    #[Test]
    public function extract_reference_exactly_10_chars_before_value(): void
    {
        // "reference " is exactly 10 characters
        $this->assertSame('X', Order::extractCustomerReferenceNumber('reference X'));
    }

    #[Test]
    public function extract_reference_handles_very_long_prefix(): void
    {
        $longPrefix = \str_repeat('Note: ', 100);
        $this->assertSame('ABC123', Order::extractCustomerReferenceNumber("{$longPrefix}Reference ABC123"));
    }

    /*
    |--------------------------------------------------------------------------
    | Category 17: Constants Verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function comment_delim_old_constant_is_correct(): void
    {
        $this->assertSame(':-', Order::COMMENT_DELIM_OLD);
    }

    #[Test]
    public function comment_delim_new_constant_is_correct(): void
    {
        $this->assertSame('*>', Order::COMMENT_DELIM_NEW);
    }
}
