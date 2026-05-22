<?php

declare(strict_types=1);

namespace App\Infrastructure\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalises phone numbers to E.164 format for hashing in conversion uploads.
 *
 * Returns null on parse failure or invalid number — callers skip silently.
 * Default region GB: numbers without country code assumed to be UK.
 */
final readonly class PhoneNormalisationService
{
    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    public function toE164(?string $phone, string $defaultRegion = 'GB'): ?string
    {
        if ($phone === null || \mb_trim($phone) === '') {
            return null;
        }

        $e164 = null;

        try {
            $parsed = $this->phoneUtil->parse(\mb_trim($phone), $defaultRegion);

            if ($this->phoneUtil->isValidNumber($parsed)) {
                $e164 = $this->phoneUtil->format($parsed, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // @ignoreException — unparseable numbers are silently skipped; callers treat null as "no phone"
        }

        return $e164;
    }
}
