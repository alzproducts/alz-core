<?php

declare(strict_types=1);

namespace App\Domain\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Payment Method Value Object.
 *
 * Represents an available payment method in the domain.
 * Minimal for now - just name. Can be expanded with additional
 * fields (enabled status, configuration) as business needs evolve.
 *
 * Note: External system IDs are not included in domain objects.
 */
final readonly class PaymentMethod
{
    public function __construct(
        public string $name,
    ) {
        Assert::notEmpty($name, 'Payment method name cannot be empty');
    }
}
