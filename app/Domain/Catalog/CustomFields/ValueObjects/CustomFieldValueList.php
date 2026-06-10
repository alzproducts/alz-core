<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use Countable;
use DateTimeImmutable;
use Generator;
use IteratorAggregate;

/**
 * Typed collection of custom field values with name-based lookups.
 *
 * $fields stays private to force callers through the typed accessors;
 * toList() is the explicit escape hatch.
 *
 * @implements IteratorAggregate<int, AbstractCustomFieldValue>
 */
final readonly class CustomFieldValueList implements Countable, IteratorAggregate
{
    /** @param list<AbstractCustomFieldValue> $fields */
    private function __construct(
        private array $fields,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param list<AbstractCustomFieldValue> $fields */
    public static function from(array $fields): self
    {
        return new self($fields);
    }

    public function findByName(string $name): ?AbstractCustomFieldValue
    {
        return \array_find(
            $this->fields,
            static fn(AbstractCustomFieldValue $field): bool => $field->name() === $name,
        );
    }

    /**
     * Strict: returns the value only when the matched field is a
     * StringCustomFieldValue with a non-empty value.
     */
    public function stringByName(string $name): ?string
    {
        $field = $this->findByName($name);

        if (! $field instanceof StringCustomFieldValue) {
            return null;
        }

        return $field->value !== '' ? $field->value : null;
    }

    /**
     * Defensive: returns DateTime-typed fields directly and parses date-shaped
     * String-typed fields, so both storage representations resolve.
     */
    public function dateTimeByName(string $name): ?DateTimeImmutable
    {
        $field = $this->findByName($name);

        if ($field instanceof DateTimeCustomFieldValue) {
            return $field->value;
        }

        if ($field instanceof StringCustomFieldValue && $field->value !== '') {
            $parsed = \date_create_immutable($field->value);

            return $parsed !== false ? $parsed : null;
        }

        return null;
    }

    /**
     * Subset containing only the named fields, preserving this list's order.
     * Empty $names means "no filter" and returns the full list.
     *
     * @param list<string> $names
     */
    public function withNames(array $names): self
    {
        if ($names === []) {
            return $this;
        }

        return new self(\array_values(\array_filter(
            $this->fields,
            static fn(AbstractCustomFieldValue $field): bool => \in_array($field->name(), $names, true),
        )));
    }

    /**
     * Key by field name; on duplicate names the last occurrence wins.
     *
     * @return array<string, AbstractCustomFieldValue>
     */
    public function mapByName(): array
    {
        $indexed = [];
        foreach ($this->fields as $field) {
            $indexed[$field->name()] = $field;
        }

        return $indexed;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function count(): int
    {
        return \count($this->fields);
    }

    /** @return Generator<int, AbstractCustomFieldValue> */
    public function getIterator(): Generator
    {
        yield from $this->fields;
    }

    /** @return list<AbstractCustomFieldValue> */
    public function toList(): array
    {
        return $this->fields;
    }
}
