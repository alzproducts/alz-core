<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\Normalizers;

/**
 * Normalises raw payment method values for Mixpanel event properties.
 *
 * Maps internal PaymentMethod enum values to display-friendly labels for
 * Mixpanel dashboards. Unmapped values fall through unchanged to avoid
 * silent data loss.
 *
 * Extend the LABELS map to add new display overrides.
 */
final class PaymentMethodNormaliser
{
    /**
     * Display label overrides for known payment methods.
     *
     * Keys are PaymentMethod enum string values; values are the
     * Mixpanel-facing display labels.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'paypal' => 'PayPal',
    ];

    /**
     * Return the display-friendly label for a payment method value.
     *
     * Falls through to the original value when no mapping is defined.
     */
    public static function normalise(string $paymentMethod): string
    {
        return self::LABELS[$paymentMethod] ?? $paymentMethod;
    }
}
