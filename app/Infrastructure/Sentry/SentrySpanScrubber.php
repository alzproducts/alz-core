<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

final class SentrySpanScrubber
{
    /**
     * intentional: rebuilds the span. `Span::setData()` merges, so it cannot remove a key.
     */
    public static function scrub(Span $span): Span
    {
        $data = \array_diff_key($span->getData(), \array_flip(SentryUrlQueryStripper::QUERY_KEYS));
        $description = $span->getDescription();

        $context = SpanContext::make()
            ->setTraceId($span->getTraceId())
            ->setSpanId($span->getSpanId())
            ->setParentSpanId($span->getParentSpanId())
            ->setStartTimestamp($span->getStartTimestamp())
            ->setEndTimestamp($span->getEndTimestamp())
            ->setOp($span->getOp())
            ->setStatus($span->getStatus())
            ->setOrigin($span->getOrigin())
            ->setSampled($span->getSampled())
            ->setDescription($description === null ? null : SentryUrlQueryStripper::text($description))
            ->setData(SentryUrlQueryStripper::values($data))
            ->setTags(\array_map(SentryUrlQueryStripper::text(...), $span->getTags()));

        return new Span($context);
    }
}
