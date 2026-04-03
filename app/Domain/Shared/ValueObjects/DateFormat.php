<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * Shared date format constants for domain-wide use.
 *
 * Centralises display format strings so front-end consumers
 * receive consistently formatted dates without duplicating
 * format strings across value objects.
 */
final class DateFormat
{
    /**
     * UK date format: dd/mm/yyyy
     */
    public const string DEFAULT_DATE_FORMAT = 'd/m/Y';
}
