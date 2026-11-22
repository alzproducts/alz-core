<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use SensitiveParameter;

/**
 * Posting orders to QuickBooks Online: the item and customer finds a document needs, and the document creates. Names match ignoring case,
 * and nothing is cached between calls (ADR-0011).
 */
interface QuickBooksOrderPostingInterface
{
    /**
     * @param list<Sku> $skus an empty list makes no QBO call
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when a query cannot start before the deadline
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row lacks a name or id
     */
    public function findItems(#[SensitiveParameter] ApiKeyToken $accessToken, array $skus, Deadline $deadline): ItemLookupResult;

    /**
     * Finds each draft's customer by display name, active or not, and creates each one missing. A create's Fault is that
     * name's outcome, never a throw.
     *
     * @param list<CustomerDraftDTO> $customers an empty list makes no QBO call
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when a call cannot start before the deadline
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row or created customer lacks an id
     */
    public function findOrCreateCustomers(#[SensitiveParameter] ApiKeyToken $accessToken, array $customers, Deadline $deadline): CustomerResolutionResult;

    /**
     * Creates the documents in one batch call. Once the call may have reached QBO, every failure but a rejected token is
     * an outcome: `Ambiguous` means QBO may hold the document.
     *
     * @param non-empty-list<PostableDocumentDTO> $documents 1 to 20, never split
     *
     * @return non-empty-list<BatchItemOutcomeResult> one per document, in input order
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException when the limiter is down before the first send; nothing was sent
     * @throws QuickBooksDeadlineExceededException when the call cannot start before the deadline; nothing was sent
     */
    public function createDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $documents, Deadline $deadline): array;
}
