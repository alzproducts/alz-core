<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;

/**
 * Trait for automatic Domain ↔ Eloquent mapping via reflection.
 *
 * Provides default implementations of toDomain() and fromDomainAttributes()
 * that automatically map between snake_case model attributes and camelCase
 * domain properties using reflection.
 *
 * Usage:
 * - Use this trait on simple Eloquent models with 1:1 property mappings
 * - Implement domainClass() to specify the target Domain class
 * - For complex mappings (nested objects, enums, collections), don't use this
 *   trait — implement the interface methods manually or delegate to a mapper
 *
 * @see EloquentDomainMappableInterface
 */
trait AutoDomainMappingTrait
{
    /**
     * The fully qualified class name of the Domain object.
     *
     * @return class-string
     */
    abstract protected function domainClass(): string;

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     *
     * Maps snake_case model attributes to camelCase constructor parameters
     * using reflection on the domain class constructor.
     */
    public function toDomain(): object
    {
        $class = $this->domainClass();
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $snakeName = Str::snake($param->getName());
            $args[$param->getName()] = $this->getAttribute($snakeName);
        }

        return new $class(...$args);
    }

    /**
     * Convert a Domain object to Eloquent model attributes.
     *
     * Maps camelCase domain properties to snake_case attribute names
     * using reflection on the domain object's public properties.
     *
     * @param object $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        $reflection = new ReflectionClass($entity);
        $attributes = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $snakeName = Str::snake($prop->getName());
            $attributes[$snakeName] = $prop->getValue($entity);
        }

        return $attributes;
    }
}
