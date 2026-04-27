<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp;

/**
 * Transport-level config for ClickUp HTTP calls.
 *
 * Use-case-level config (list ID, complete-status keyword) is resolved and validated
 * directly in the service provider's use-case bind closures — those values are not
 * read by the transport itself.
 */
final readonly class ClickUpConfig
{
    public function __construct(
        public string $baseUrl,
        public int $timeoutSeconds,
    ) {}
}
