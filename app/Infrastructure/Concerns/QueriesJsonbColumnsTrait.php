<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Webmozart\Assert\Assert;

/**
 * Eloquent query scopes for PostgreSQL JSONB column operations.
 *
 * Provides type-safe, readable scopes that replace verbose whereRaw()
 * clauses for common JSONB query patterns.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait QueriesJsonbColumnsTrait
{
    /**
     * Scope: where a JSONB column contains an array at the given key.
     *
     * Uses jsonb_typeof to guard against missing keys (returns NULL),
     * null JSON values (returns 'null'), and non-array types.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereJsonbIsArray(Builder $query, string $column, string $key): void
    {
        self::assertSafeIdentifier($column);
        self::assertSafeIdentifier($key);

        $query->getQuery()->whereRaw("jsonb_typeof({$column}->'{$key}') = 'array'");
    }

    /**
     * Scope: where a JSONB column contains a non-empty array at the given key.
     *
     * Extends whereJsonbIsArray with a length check to filter empty arrays.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereJsonbArrayNotEmpty(Builder $query, string $column, string $key): void
    {
        self::assertSafeIdentifier($column);
        self::assertSafeIdentifier($key);

        $query->getQuery()->whereRaw(
            "jsonb_typeof({$column}->'{$key}') = 'array' AND jsonb_array_length({$column}->'{$key}') > 0",
        );
    }

    private static function assertSafeIdentifier(string $value): void
    {
        Assert::regex($value, '/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'JSONB scope identifier must be alphanumeric: %s');
    }
}
