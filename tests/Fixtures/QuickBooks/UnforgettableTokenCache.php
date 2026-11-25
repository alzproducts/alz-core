<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateTimeImmutable;
use Override;
use SensitiveParameter;

/**
 * The real cache, except that forgetting the token throws.
 */
final class UnforgettableTokenCache implements QuickBooksAccessTokenCacheInterface
{
    public int $forgets = 0;

    public function __construct(
        private readonly QuickBooksAccessTokenCacheInterface $inner,
    ) {}

    #[Override]
    public function get(): ?ApiKeyToken
    {
        return $this->inner->get();
    }

    #[Override]
    public function put(#[SensitiveParameter] ApiKeyToken $accessToken, DateTimeImmutable $expiresAt): void
    {
        $this->inner->put($accessToken, $expiresAt);
    }

    #[Override]
    public function forget(): void
    {
        $this->forgets++;

        throw new ExternalServiceUnavailableException('Cache');
    }
}
