<?php

declare(strict_types=1);

namespace App\Application\Contracts;

/**
 * Email validation service contract.
 *
 * Validates email addresses using RFC syntax and DNS MX record checks.
 */
interface EmailValidationServiceInterface
{
    /**
     * Validate email with RFC syntax + DNS MX record check.
     *
     * Note: DNS validation may have false negatives (valid email but DNS lookup fails)
     * so this should be used for informational purposes, not blocking.
     *
     * @param string $email The email address to validate
     *
     * @return bool True if email appears valid, false otherwise
     */
    public function isValid(string $email): bool;
}
