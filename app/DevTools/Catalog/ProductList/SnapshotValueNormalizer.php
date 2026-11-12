<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use DateTimeInterface;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

/**
 * Turns a reader's return value into JSON-encodable data that keeps every stored property, private ones included,
 * so a snapshot diff catches any field a reader changes.
 */
final class SnapshotValueNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s.uP'),
            \is_object($value) => self::normalizeObject($value),
            \is_array($value) => \array_map(self::normalize(...), $value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeObject(object $value): array
    {
        $normalized = ['@class' => $value::class];

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if (self::isStoredValue($property, $value)) {
                $normalized[$property->getName()] = self::normalize($property->getValue($value));
            }
        }

        return $normalized;
    }

    private static function isStoredValue(ReflectionProperty $property, object $owner): bool
    {
        return ! $property->isStatic() && ! $property->isVirtual() && $property->isInitialized($owner);
    }
}
