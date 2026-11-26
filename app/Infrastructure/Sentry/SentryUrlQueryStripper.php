<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Throwable;

/**
 * Cuts the query and fragment from every absolute HTTP URL in a value bound for Sentry.
 */
final class SentryUrlQueryStripper
{
    /** The SDK records these beside a URL it has already cut. */
    public const array QUERY_KEYS = ['http.query', 'http.fragment'];

    /**
     * intentional: `'` does not end a URL, because PSR-7 leaves it unencoded in a query.
     * The slashes may be JSON-escaped.
     */
    private const string URL_WITH_QUERY = '~(https?:(?:\\\\?/){2}[^\s?#"<>`]*+)[?#][^\s"<>`]*+~i';

    private const string FILTERED = '[Filtered]';

    public static function text(string $text): string
    {
        return \preg_replace(self::URL_WITH_QUERY, '$1', $text) ?? self::FILTERED;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return ($values is array<string, mixed> ? array<string, mixed> : array<array-key, mixed>)
     */
    public static function values(array $values): array
    {
        return \array_map(self::value(...), $values);
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            \is_string($value) => self::text($value),
            \is_array($value) => self::values($value),
            \is_object($value) => self::object($value),
            default => $value,
        };
    }

    /**
     * Strips what the SDK would send for an object, which is its JSON form.
     */
    private static function object(object $value): mixed
    {
        try {
            $json = \json_encode($value, \JSON_THROW_ON_ERROR);

            return self::value(\json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
        } catch (Throwable) { // @ignoreException - a value that cannot be inspected is withheld, never sent as is
            return self::FILTERED;
        }
    }
}
