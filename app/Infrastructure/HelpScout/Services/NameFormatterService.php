<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Services;

use TheIconic\NameParser\Parser as NameParser;

/**
 * Formats names for HelpScout customer records.
 *
 * Uses theiconic/name-parser to intelligently split full names into
 * first name and last name, handling:
 * - Titles (Mr, Mrs, Dr, etc.)
 * - Suffixes (Jr, Sr, III, etc.)
 * - Middle names (included with last name)
 * - Multi-part last names
 *
 * Best-effort parsing that never loses data - if parsing fails,
 * the full input is used as the first name.
 */
final readonly class NameFormatterService
{
    private NameParser $parser;

    public function __construct()
    {
        $this->parser = new NameParser();
    }

    /**
     * Parse a full name into first and last name components.
     *
     * Middle names are included with last name to preserve full name
     * while fitting into first/last name fields.
     *
     * @param string $fullName The full name to parse
     * @return array{firstName: string, lastName: string|null}
     */
    public function parse(string $fullName): array
    {
        $trimmed = \mb_trim($fullName);

        if ($trimmed === '') {
            return ['firstName' => '', 'lastName' => null];
        }

        $parsed = $this->parser->parse($trimmed);

        return self::buildResult(
            $trimmed,
            \mb_trim($parsed->getFirstname()),
            \mb_trim($parsed->getMiddlename()),
            \mb_trim($parsed->getLastname()),
        );
    }

    /**
     * Build result with fallback and middle name resolution.
     *
     * @return array{firstName: string, lastName: string|null}
     */
    private static function buildResult(string $fallback, string $firstName, string $middleName, string $lastName): array
    {
        if ($firstName === '' && $lastName === '') {
            return ['firstName' => $fallback, 'lastName' => null];
        }

        return [
            'firstName' => $firstName,
            'lastName' => self::resolveLastName($lastName, $middleName),
        ];
    }

    /**
     * Merge middle name into last name.
     *
     * "John Michael Smith" → lastName: "Michael Smith"
     */
    private static function resolveLastName(string $lastName, string $middleName): ?string
    {
        if ($middleName !== '' && $lastName !== '') {
            return $middleName . ' ' . $lastName;
        }

        if ($middleName !== '') {
            return $middleName;
        }

        return $lastName !== '' ? $lastName : null;
    }

    /**
     * Get just the first name from a full name.
     */
    public function getFirstName(string $fullName): string
    {
        return $this->parse($fullName)['firstName'];
    }

    /**
     * Get just the last name from a full name.
     *
     * Returns null if no last name could be identified.
     */
    public function getLastName(string $fullName): ?string
    {
        return $this->parse($fullName)['lastName'];
    }
}
