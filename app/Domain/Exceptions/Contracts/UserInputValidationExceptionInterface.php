<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Contracts;

/**
 * Marker interface for validation failures caused by user input.
 *
 * Exceptions implementing this interface are excluded from Sentry reporting
 * because they represent expected user errors (bad prices, invalid formats),
 * not application faults.
 */
interface UserInputValidationExceptionInterface {}
