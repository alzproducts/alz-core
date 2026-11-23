<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

/**
 * The QuickBooks company this environment is configured for. Reading it makes no call.
 */
interface QuickBooksEnvironmentInterface
{
    public function isSandbox(): bool;

    public function realmId(): string;
}
