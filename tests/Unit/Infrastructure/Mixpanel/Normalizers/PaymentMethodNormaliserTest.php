<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mixpanel\Normalizers;

use App\Infrastructure\Mixpanel\Normalizers\PaymentMethodNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PaymentMethodNormaliser::class)]
final class PaymentMethodNormaliserTest extends TestCase
{
    #[Test]
    public function it_normalises_paypal_to_display_label(): void
    {
        $result = PaymentMethodNormaliser::normalise('paypal');

        $this->assertSame('PayPal', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unmappedPaymentMethodProvider(): array
    {
        return [
            'card falls through unchanged' => ['card'],
            'admin falls through unchanged' => ['admin'],
            'credit falls through unchanged' => ['credit'],
            'unknown falls through unchanged' => ['unknown'],
            'arbitrary value falls through unchanged' => ['some_future_method'],
        ];
    }

    #[Test]
    #[DataProvider('unmappedPaymentMethodProvider')]
    public function it_passes_through_unmapped_values_unchanged(string $paymentMethod): void
    {
        $result = PaymentMethodNormaliser::normalise($paymentMethod);

        $this->assertSame($paymentMethod, $result);
    }
}
