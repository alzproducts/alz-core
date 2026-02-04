<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Application\Contracts\EmailValidationServiceInterface;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Email validation service using Laravel's RFC + DNS validation.
 *
 * Used to detect potentially invalid email addresses before attempting
 * to send to them via HelpScout.
 */
final readonly class EmailValidationService implements EmailValidationServiceInterface
{
    /**
     * @throws RuntimeException If validator cannot be created (should never happen)
     */
    public function isValid(string $email): bool
    {
        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'email:rfc,dns'],
        );

        return ! $validator->fails();
    }
}
