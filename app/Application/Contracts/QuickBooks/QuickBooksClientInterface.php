<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use SensitiveParameter;

/**
 * QuickBooks Online Accounting API reads.
 */
interface QuickBooksClientInterface
{
    /**
     * @return non-empty-string
     *
     * @throws AuthenticationExpiredException      when the access token is rejected (401 or 403)
     * @throws ExternalServiceUnavailableException
     * @throws UnexpectedApiResultException        on a missing company or a blank name
     */
    public function companyName(#[SensitiveParameter] ApiKeyToken $accessToken): string;
}
