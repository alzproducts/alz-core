<?php

/** @noinspection PhpClassNamingConventionInspection */

declare(strict_types=1);

namespace App\Domain\CustomerService\Exceptions;

use App\Domain\Exceptions\DomainException;

/**
 * Thrown when a user's email cannot be matched to a customer service account.
 *
 * This indicates the authenticated user doesn't have a corresponding
 * account in the customer service platform.
 *
 * Use cases:
 * - User exists in auth system but not in customer service platform
 * - Email mismatch between systems
 * - New employee not yet provisioned
 */
final class CustomerServiceUserNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $email,
    ) {
        parent::__construct("No customer service account found for email: {$email}");
    }
}
