<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Stacktrace;

/**
 * Strips URL queries from every part of an outgoing event. Breadcrumbs are stripped earlier,
 * as they are recorded (`SentryBeforeBreadcrumbCallback`).
 */
final class SentryEventScrubber
{
    public static function scrub(Event $event): Event
    {
        self::scrubRequest($event);
        self::scrubMessage($event);
        self::scrubExceptions($event);
        self::scrubAttachedData($event);
        $event->setSpans(\array_map(SentrySpanScrubber::scrub(...), $event->getSpans()));

        return $event;
    }

    /**
     * Sentry holds the incoming query twice: `query_string` and the `url` itself. The `Referer` header may add a third.
     */
    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();
        unset($request['query_string']);

        if (\is_string($request['url'] ?? null)) {
            $request['url'] = \explode('?', $request['url'], 2)[0];
        }

        $event->setRequest(SentryUrlQueryStripper::values($request));
    }

    private static function scrubMessage(Event $event): void
    {
        $message = $event->getMessage();
        if ($message === null) {
            return;
        }

        $formatted = $event->getMessageFormatted();
        $event->setMessage(
            SentryUrlQueryStripper::text($message),
            \array_map(SentryUrlQueryStripper::text(...), $event->getMessageParams()),
            $formatted === null ? null : SentryUrlQueryStripper::text($formatted),
        );
    }

    private static function scrubExceptions(Event $event): void
    {
        foreach ($event->getExceptions() as $exception) {
            self::scrubException($exception);
        }

        self::scrubStacktrace($event->getStacktrace());
    }

    private static function scrubException(ExceptionDataBag $exception): void
    {
        $exception->setValue(SentryUrlQueryStripper::text($exception->getValue()));
        self::scrubStacktrace($exception->getStacktrace());
    }

    private static function scrubStacktrace(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars(SentryUrlQueryStripper::values($frame->getVars()));
        }
    }

    private static function scrubAttachedData(Event $event): void
    {
        $event->setExtra(SentryUrlQueryStripper::values($event->getExtra()));
        $event->setTags(\array_map(SentryUrlQueryStripper::text(...), $event->getTags()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, SentryUrlQueryStripper::values($context));
        }
    }
}
