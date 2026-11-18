<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Psr\Log\LoggerInterface;

final readonly class StartQuickBooksConnectionUseCase
{
    public function __construct(
        private QuickBooksStateStoreInterface $states,
        private QuickBooksOAuthClientInterface $oauthClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return string the Intuit consent URL to open
     *
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): string
    {
        $this->logger->info('QuickBooks connection started');

        $url = $this->oauthClient->authorizationUrl($this->states->issue());

        $this->logger->info('QuickBooks consent URL issued');

        return $url;
    }
}
