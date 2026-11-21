<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;

/**
 * Single-use OAuth `state` values: the connect callback's only CSRF guard, since the redirect carries no JWT.
 */
interface QuickBooksStateStoreInterface
{
    /**
     * Issues a new state: 64 lowercase hex characters.
     *
     * @throws ExternalServiceUnavailableException
     */
    public function issue(): string;

    /**
     * Claims the state; true for exactly one caller, false when it is unknown, expired or already claimed.
     *
     * @throws ExternalServiceUnavailableException
     */
    public function consume(string $state): bool;
}
