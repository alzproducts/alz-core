<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use SensitiveParameter;

/**
 * Nothing is cached between calls (ADR-0011).
 */
interface QuickBooksMappingCandidatesInterface
{
    /**
     * @param Deadline|null $deadline shared by every call; null gives each call its own time limit
     *
     * @return list<MappingCandidateDTO> active and inactive
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a call past its time limit
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row lacks a name or id, or Preferences lacks its sales form section
     */
    public function candidates(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity, ?Deadline $deadline = null): array;
}
