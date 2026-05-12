<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use DateTimeImmutable;
use DateTimeZone;
use Webmozart\Assert\Assert;

/**
 * Custom field value containing a date or datetime.
 *
 * Used for field types:
 * - Date: Date only (no time component)
 * - DateTime: Date with time
 *
 * Accepts Unix timestamps (int) or ISO 8601 date strings. All values normalised to Europe/London.
 */
final readonly class DateTimeCustomFieldValue extends AbstractCustomFieldValue
{
    private const string TIMEZONE = 'Europe/London';

    /**
     * @throws InvalidCustomFieldValueException If value cannot be parsed as timestamp
     */
    public function __construct(
        ConfiguredFieldDefinition $definition,
        public DateTimeImmutable $value,
    ) {
        Assert::true(
            $definition->base->type->isDateType(),
            "DateTimeCustomFieldValue requires date type (Date/DateTime), got '{$definition->base->type->value}'",
        );

        parent::__construct($definition);
    }

    /**
     * Create from a Unix timestamp.
     *
     * @param int $timestamp Unix timestamp in seconds
     *
     * @throws InvalidCustomFieldValueException If timestamp is invalid
     */
    public static function fromTimestamp(ConfiguredFieldDefinition $definition, int $timestamp): self
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $parsed = DateTimeImmutable::createFromFormat('U', (string) $timestamp);

        if ($parsed === false) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: 'int (invalid timestamp)',
                rawValue: $timestamp,
            );
        }

        return new self($definition, $parsed->setTimezone($timezone));
    }

    /**
     * Create from an ISO 8601 date or datetime string (e.g. "2026-06-15" or "2026-06-15T10:30:00").
     *
     * @throws InvalidCustomFieldValueException If the string cannot be parsed
     */
    public static function fromDateString(ConfiguredFieldDefinition $definition, string $dateString): self
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $parsed = \date_create_immutable($dateString, $timezone);

        if ($parsed === false) {
            throw new InvalidCustomFieldValueException(
                fieldName: $definition->base->name,
                expectedType: $definition->base->type,
                actualType: 'string (invalid date)',
                rawValue: $dateString,
            );
        }

        return new self($definition, $parsed->setTimezone($timezone));
    }

    public function rawValue(): DateTimeImmutable
    {
        return $this->value;
    }
}
