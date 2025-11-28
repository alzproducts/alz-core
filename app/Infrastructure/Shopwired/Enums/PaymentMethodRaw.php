<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use InvalidArgumentException;

/**
 * Raw payment method values from ShopWired API.
 *
 * This enum captures the exact string values returned by the API.
 * Maps to Domain PaymentMethod enum via toDomain() method.
 *
 * When a new payment processor is added, add the case here and
 * update the toDomain() mapping.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
enum PaymentMethodRaw: string
{
    case AdminOrder = 'Admin Order';
    case PayPal = 'PayPal';
    case Credit = 'Credit';
    case Offline = 'Offline';
    case OpayoHosted = 'Opayo Hosted';
    case OpayoDirect = 'Opayo Direct';
    case OpayoForm = 'Opayo Form';
    case SagepayDirect = 'Sagepay Direct';
    case SagepayForm = 'Sagepay Form';
    case SagepayHosted = 'Sagepay Hosted';

    /**
     * Convert to Domain PaymentMethod.
     *
     * Groups raw API values into business-meaningful categories.
     */
    public function toDomain(): PaymentMethod
    {
        return match ($this) {
            self::AdminOrder => PaymentMethod::Admin,
            self::PayPal => PaymentMethod::PayPal,
            self::Credit => PaymentMethod::Credit,
            self::Offline,
            self::OpayoHosted,
            self::OpayoDirect,
            self::OpayoForm,
            self::SagepayDirect,
            self::SagepayForm,
            self::SagepayHosted => PaymentMethod::Card,
        };
    }

    /**
     * Create from raw API string.
     *
     * @throws InvalidArgumentException When payment method is unknown
     */
    public static function fromApiValue(string $value): self
    {
        // Try exact match first
        $exact = self::tryFrom($value);
        if ($exact !== null) {
            return $exact;
        }

        // Fallback for unknown Sagepay/Sage Pay variants
        if (\str_contains($value, 'Sagepay') || \str_contains($value, 'Sage Pay')) {
            return self::SagepayDirect;
        }

        throw new InvalidArgumentException("Unknown payment method: {$value}");
    }
}
