<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Override;
use Webmozart\Assert\Assert;

/**
 * Custom field value containing a date or datetime.
 *
 * Used for field types:
 * - Date: Date only (no time component)
 * - DateTime: Date with time
 *
 * Parses Unix timestamps from the API into DateTimeImmutable with Europe/London timezone.
 *
 * NOTE: Assumed API returns Unix timestamps. Verify actual format during integration testing.
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

        // Convert to London timezone
        return new self($definition, $parsed->setTimezone($timezone));
    }

    public function rawValue(): DateTimeImmutable
    {
        return $this->value;
    }

    /**
     * @return array{name: string, type: string, label: ?string, value: string, allowed_values: ?list<string>, sort_order: ?int}
     */
    #[Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'value' => $this->value->format(DateTimeInterface::ATOM)];
    }
}
