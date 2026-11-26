<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Sentry\Breadcrumb;

/**
 * Strips URL queries from each breadcrumb as it is recorded: HTTP client calls and log lines carry them.
 */
final class SentryBeforeBreadcrumbCallback
{
    /**
     * Required for config:cache serialization (var_export).
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }

    public function __invoke(Breadcrumb $breadcrumb): Breadcrumb
    {
        foreach (SentryUrlQueryStripper::QUERY_KEYS as $key) {
            $breadcrumb = $breadcrumb->withoutMetadata($key);
        }

        foreach (SentryUrlQueryStripper::values($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata($name, $value);
        }

        $message = $breadcrumb->getMessage();

        return $message === null ? $breadcrumb : $breadcrumb->withMessage(SentryUrlQueryStripper::text($message));
    }
}
