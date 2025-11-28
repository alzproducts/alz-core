<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Enums;

use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Infrastructure\Shopwired\Enums\PaymentMethodRaw;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PaymentMethodRaw Unit Tests.
 *
 * Tests the raw payment method enum mapping from ShopWired API values
 * to domain PaymentMethod values.
 */
#[CoversClass(PaymentMethodRaw::class)]
final class PaymentMethodRawTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromApiValue Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validApiValuesProvider')]
    public function from_api_value_creates_enum_for_valid_values(
        string $apiValue,
        PaymentMethodRaw $expectedEnum,
    ): void {
        $result = PaymentMethodRaw::fromApiValue($apiValue);

        self::assertSame($expectedEnum, $result);
    }

    /**
     * @return array<string, array{string, PaymentMethodRaw}>
     */
    public static function validApiValuesProvider(): array
    {
        return [
            'Admin Order' => ['Admin Order', PaymentMethodRaw::AdminOrder],
            'PayPal' => ['PayPal', PaymentMethodRaw::PayPal],
            'Credit' => ['Credit', PaymentMethodRaw::Credit],
            'Offline' => ['Offline', PaymentMethodRaw::Offline],
            'Opayo Hosted' => ['Opayo Hosted', PaymentMethodRaw::OpayoHosted],
            'Opayo Direct' => ['Opayo Direct', PaymentMethodRaw::OpayoDirect],
            'Opayo Form' => ['Opayo Form', PaymentMethodRaw::OpayoForm],
            'Sagepay Direct' => ['Sagepay Direct', PaymentMethodRaw::SagepayDirect],
            'Sagepay Form' => ['Sagepay Form', PaymentMethodRaw::SagepayForm],
            'Sagepay Hosted' => ['Sagepay Hosted', PaymentMethodRaw::SagepayHosted],
        ];
    }

    #[Test]
    public function from_api_value_throws_for_unknown_payment_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payment method: Stripe');

        PaymentMethodRaw::fromApiValue('Stripe');
    }

    #[Test]
    public function from_api_value_throws_for_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payment method: ');

        PaymentMethodRaw::fromApiValue('');
    }

    #[Test]
    public function from_api_value_is_case_sensitive(): void
    {
        // API values are case-sensitive - lowercase should fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown payment method: paypal');

        PaymentMethodRaw::fromApiValue('paypal');
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('domainMappingProvider')]
    public function to_domain_maps_to_correct_payment_method(
        PaymentMethodRaw $raw,
        PaymentMethod $expectedDomain,
    ): void {
        $result = $raw->toDomain();

        self::assertSame($expectedDomain, $result);
    }

    /**
     * @return array<string, array{PaymentMethodRaw, PaymentMethod}>
     */
    public static function domainMappingProvider(): array
    {
        return [
            'AdminOrder maps to Admin' => [PaymentMethodRaw::AdminOrder, PaymentMethod::Admin],
            'PayPal maps to PayPal' => [PaymentMethodRaw::PayPal, PaymentMethod::PayPal],
            'Credit maps to Credit' => [PaymentMethodRaw::Credit, PaymentMethod::Credit],
            'Offline maps to Unknown' => [PaymentMethodRaw::Offline, PaymentMethod::Unknown],
            'OpayoHosted maps to Card' => [PaymentMethodRaw::OpayoHosted, PaymentMethod::Card],
            'OpayoDirect maps to Card' => [PaymentMethodRaw::OpayoDirect, PaymentMethod::Card],
            'OpayoForm maps to Card' => [PaymentMethodRaw::OpayoForm, PaymentMethod::Card],
            'SagepayDirect maps to Card' => [PaymentMethodRaw::SagepayDirect, PaymentMethod::Card],
            'SagepayForm maps to Card' => [PaymentMethodRaw::SagepayForm, PaymentMethod::Card],
            'SagepayHosted maps to Card' => [PaymentMethodRaw::SagepayHosted, PaymentMethod::Card],
        ];
    }

    #[Test]
    public function all_enum_cases_have_domain_mapping(): void
    {
        // Ensure every PaymentMethodRaw case has a toDomain mapping
        foreach (PaymentMethodRaw::cases() as $case) {
            $domain = $case->toDomain();

            self::assertInstanceOf(PaymentMethod::class, $domain);
        }
    }

    #[Test]
    public function card_payment_methods_all_map_to_same_domain(): void
    {
        // Business rule: All card-based payment processors (Opayo/Sagepay) map to PaymentMethod::Card
        $cardMethods = [
            PaymentMethodRaw::OpayoHosted,
            PaymentMethodRaw::OpayoDirect,
            PaymentMethodRaw::OpayoForm,
            PaymentMethodRaw::SagepayDirect,
            PaymentMethodRaw::SagepayForm,
            PaymentMethodRaw::SagepayHosted,
        ];

        foreach ($cardMethods as $method) {
            self::assertSame(
                PaymentMethod::Card,
                $method->toDomain(),
                "Expected {$method->name} to map to Card",
            );
        }
    }
}
