<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Sentry\Event;

/**
 * Strips the query from events for routes whose query carries a secret (the QBO OAuth code).
 * Sentry holds the query twice: `query_string` and the `url` itself.
 */
final class SentryRequestScrubber
{
    private const array SECRET_QUERY_PATHS = ['/quickbooks/callback'];

    public static function scrub(Event $event): Event
    {
        $request = $event->getRequest();
        $url = $request['url'] ?? null;
        if (!\is_string($url) || !\in_array(self::routedPath($url), self::SECRET_QUERY_PATHS, true)) {
            return $event;
        }

        unset($request['query_string']);
        $request['url'] = \explode('?', $url, 2)[0];

        return $event->setRequest($request);
    }

    /**
     * Normalised the way Laravel's router matches (decoded, trailing slash trimmed), so a variant URL still scrubs.
     */
    private static function routedPath(string $url): string
    {
        $path = \parse_url($url, \PHP_URL_PATH);

        return '/' . \mb_trim(\rawurldecode(\is_string($path) ? $path : ''), '/');
    }
}
