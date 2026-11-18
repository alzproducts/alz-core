<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\QuickBooks\ValueObjects\QuickBooksTokenGrant;
use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class QuickBooksOAuthClient implements QuickBooksOAuthClientInterface
{
    public function __construct(
        private QuickBooksOAuthHttpTransport $transport,
        private QuickBooksConfig $config,
    ) {}

    public function authorizationUrl(string $state): string
    {
        return QuickBooksConfig::AUTHORIZE_URL . '?' . \http_build_query([
            'client_id' => $this->config->clientId,
            'response_type' => 'code',
            'scope' => QuickBooksConfig::SCOPE,
            'redirect_uri' => $this->config->redirectUri,
            'state' => $state,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function expectedRealmId(): string
    {
        return $this->config->realmId;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function refresh(#[SensitiveParameter] ApiKeyToken $refreshToken): QuickBooksTokenGrant
    {
        return $this->grantFrom($this->transport->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken->value,
        ]));
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function exchangeCode(#[SensitiveParameter] string $code): QuickBooksTokenGrant
    {
        return $this->grantFrom($this->transport->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri,
        ]));
    }

    /**
     * @param array<mixed> $body
     *
     * @throws InvalidApiResponseException
     */
    private function grantFrom(#[SensitiveParameter] array $body): QuickBooksTokenGrant
    {
        $now = CarbonImmutable::now();
        $hardExpiresIn = $body['x_refresh_token_hard_expires_in'] ?? null;

        return new QuickBooksTokenGrant(
            accessToken: new ApiKeyToken(self::requiredToken($body, 'access_token')),
            accessTokenExpiresAt: $now->addSeconds(self::requiredSeconds($body, 'expires_in')),
            connection: new QuickBooksConnection(
                refreshToken: new ApiKeyToken(self::requiredToken($body, 'refresh_token')),
                refreshTokenExpiresAt: $now->addSeconds(self::requiredSeconds($body, 'x_refresh_token_expires_in')),
                refreshTokenHardExpiresAt: $hardExpiresIn === null
                    ? null
                    : $now->addSeconds(self::requiredSeconds($body, 'x_refresh_token_hard_expires_in')),
            ),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws InvalidApiResponseException
     */
    private static function requiredToken(#[SensitiveParameter] array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new InvalidApiResponseException(QuickBooksOAuthHttpTransport::SERVICE_NAME, 'Token response is missing a token field');
        }

        return $value;
    }

    /**
     * @param array<mixed> $body
     *
     * @throws InvalidApiResponseException
     */
    private static function requiredSeconds(#[SensitiveParameter] array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!\is_int($value) || $value <= 0) {
            throw new InvalidApiResponseException(QuickBooksOAuthHttpTransport::SERVICE_NAME, 'Token response is missing an expiry field');
        }

        return $value;
    }
}
