<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\QuickBooks\Results\QuickBooksTokenLeaseResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

final readonly class ImportQuickBooksTokenLeaseUseCase
{
    public function __construct(
        private QuickBooksAccessTokenCacheInterface $accessTokens,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function execute(QuickBooksTokenLeaseResult $lease): void
    {
        $this->logger->info('Importing leased QuickBooks access token');

        $this->accessTokens->put($lease->accessToken, $lease->expiresAt);

        $this->logger->info('Imported leased QuickBooks access token', [
            'expires_at' => $lease->expiresAt->format(DateTimeInterface::ATOM),
        ]);
    }
}
