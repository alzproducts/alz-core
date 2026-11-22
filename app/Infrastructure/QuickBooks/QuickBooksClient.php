<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use Override;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use SensitiveParameter;

final readonly class QuickBooksClient implements QuickBooksClientInterface
{
    public function __construct(
        private QuickBooksTransport $transport,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return non-empty-string
     *
     * @throws AuthenticationExpiredException      when the access token is rejected (401 or 403)
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException when the call cannot start before the deadline
     * @throws QuickBooksFaultException            on any other QuickBooks Fault
     * @throws UnexpectedApiResultException        on a missing company or a blank name
     */
    #[Override]
    public function companyName(#[SensitiveParameter] ApiKeyToken $accessToken, Deadline $deadline): string
    {
        $companyInfo = $this->transport->companyInfo($accessToken, $deadline);
        if (!$companyInfo instanceof IPPCompanyInfo) {
            throw $this->unexpected('CompanyInfo missing from response');
        }

        $name = self::nonBlank($companyInfo->CompanyName);
        if ($name === null) {
            throw $this->unexpected('CompanyName blank');
        }

        return $name;
    }

    /**
     * Takes mixed: the SDK types CompanyName as string, but it is null when the response omits it.
     *
     * @return non-empty-string|null
     */
    private static function nonBlank(mixed $value): ?string
    {
        return \is_string($value) && \mb_trim($value) !== '' ? $value : null;
    }

    private function unexpected(string $reason): UnexpectedApiResultException
    {
        $this->logger->error('QuickBooks API returned an unexpected company result', ['reason' => $reason]);

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $reason);
    }
}
