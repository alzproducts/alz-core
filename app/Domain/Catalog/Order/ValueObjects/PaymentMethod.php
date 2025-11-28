<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * Domain payment method categories.
 *
 * Business-meaningful groupings of payment methods, abstracted from
 * raw API values. Maps card processors (Opayo, Sagepay) to Card,
 * admin-created orders to Admin, etc.
 */
enum PaymentMethod: string
{
    case Admin = 'admin';
    case PayPal = 'paypal';
    case Credit = 'credit';
    case Card = 'card';
}
