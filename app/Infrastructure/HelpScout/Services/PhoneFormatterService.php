<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Formats phone numbers for HelpScout customer records.
 *
 * Best-effort formatting that never loses data:
 * - UK numbers: formatted as national (07931 423 843)
 * - International numbers: formatted with country code (+1 555 123 4567)
 * - Unparseable numbers: returned as-is (trimmed)
 *
 * Default region is GB - numbers without country code assumed to be UK.
 */
final readonly class PhoneFormatterService
{
    private const string DEFAULT_REGION = 'GB';

    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    /**
     * Format a phone number to consistent format.
     *
     * Returns the original input (trimmed) if the number cannot be parsed
     * or is invalid - customer data is never discarded.
     *
     * @param string $phoneNumber Raw phone number input
     * @return string Formatted phone number (or original if unparseable)
     */
    public function format(string $phoneNumber): string
    {
        $trimmed = \mb_trim($phoneNumber);

        if ($trimmed === '') {
            return '';
        }

        try {
            $parsed = $this->phoneUtil->parse($trimmed, self::DEFAULT_REGION);

            if (! $this->phoneUtil->isValidNumber($parsed)) {
                return $trimmed;
            }

            return $this->formatValidNumber($parsed);
        } catch (NumberParseException) {
            return $trimmed;
        }
    }

    /**
     * Format a validated phone number based on its region.
     *
     * UK numbers: national format (07931 423 843)
     * International: international format (+1 555 123 4567)
     */
    private function formatValidNumber(PhoneNumber $parsed): string
    {
        $regionCode = $this->phoneUtil->getRegionCodeForNumber($parsed);

        $format = $regionCode === self::DEFAULT_REGION
            ? PhoneNumberFormat::NATIONAL
            : PhoneNumberFormat::INTERNATIONAL;

        return $this->phoneUtil->format($parsed, $format);
    }

    /**
     * Check if a phone number can be parsed and is valid.
     *
     * Useful for validation feedback, but format() should still be
     * called regardless - we keep invalid numbers for human review.
     */
    public function isValid(string $phoneNumber): bool
    {
        $trimmed = \mb_trim($phoneNumber);

        if ($trimmed === '') {
            return false;
        }

        try {
            $parsed = $this->phoneUtil->parse($trimmed, self::DEFAULT_REGION);

            return $this->phoneUtil->isValidNumber($parsed);
        } catch (NumberParseException) {
            return false;
        }
    }
}
