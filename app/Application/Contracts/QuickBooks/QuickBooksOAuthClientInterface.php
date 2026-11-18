<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksTokenGrant;
use SensitiveParameter;

/**
 * Intuit's OAuth 2.0 token endpoint for the configured QBO environment.
 */
interface QuickBooksOAuthClientInterface
{
    /**
     * Intuit's consent URL for the accounting scope, carrying the given state and the configured redirect URI.
     */
    public function authorizationUrl(string $state): string;

    /**
     * The QBO company the configured keys must connect to.
     */
    public function expectedRealmId(): string;

    /**
     * @throws AuthenticationExpiredException     on invalid_grant or a 401: the connection must be remade
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function refresh(#[SensitiveParameter] ApiKeyToken $refreshToken): QuickBooksTokenGrant;

    /**
     * @throws AuthenticationExpiredException     on invalid_grant or a 401
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function exchangeCode(#[SensitiveParameter] string $code): QuickBooksTokenGrant;
}
