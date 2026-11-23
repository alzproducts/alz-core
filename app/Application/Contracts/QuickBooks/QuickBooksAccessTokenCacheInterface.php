<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * The short-lived QBO access token for the configured QBO environment.
 */
interface QuickBooksAccessTokenCacheInterface
{
    /**
     * @throws ExternalServiceUnavailableException when the cache store is unreachable
     */
    public function get(): ?ApiKeyToken;

    /**
     * When the cached access token expires at Intuit; null when nothing is cached or the expiry is unknown.
     *
     * @throws ExternalServiceUnavailableException when the cache store is unreachable
     */
    public function expiresAt(): ?DateTimeImmutable;

    /**
     * Stores the token until shortly before it expires; an already-expiring token is not stored.
     *
     * @throws ExternalServiceUnavailableException when the cache store is unreachable
     */
    public function put(#[SensitiveParameter] ApiKeyToken $accessToken, DateTimeImmutable $expiresAt): void;

    /**
     * @throws ExternalServiceUnavailableException when the cache store is unreachable
     */
    public function forget(): void;
}
