<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
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
use Override;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\Data\IPPItem;
use SensitiveParameter;

final readonly class QuickBooksClient implements QuickBooksClientInterface, QuickBooksMappingCandidatesInterface, QuickBooksOrderPostingInterface
{
    public function __construct(
        private QuickBooksTransport $transport,
        private QuickBooksCustomerProvisioner $customers,
        private QuickBooksDocumentBatchCreator $documents,
        private QuickBooksMappingCandidateReader $mappingCandidates,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return non-empty-string
     *
     * @throws AuthenticationExpiredException      when the access token is rejected (401 or 403)
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException            on any other QuickBooks Fault
     * @throws UnexpectedApiResultException        on a missing company or a blank name
     */
    #[Override]
    public function companyName(#[SensitiveParameter] ApiKeyToken $accessToken): string
    {
        $companyInfo = $this->transport->companyInfo($accessToken);
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
     * @param list<Sku> $skus an empty list makes no QBO call
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when a query cannot start before the deadline
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row lacks a name or id
     */
    #[Override]
    public function findItems(#[SensitiveParameter] ApiKeyToken $accessToken, array $skus, Deadline $deadline): ItemLookupResult
    {
        $found = [];
        foreach (QuickBooksQueryEscaper::chunks(\array_map(static fn(Sku $sku): string => $sku->value, $skus)) as $chunk) {
            $query = 'SELECT * FROM Item WHERE Name IN '
                . QuickBooksQueryEscaper::inList($chunk)
                . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS;

            foreach ($this->transport->query($accessToken, $query, $deadline) as $row) {
                $found[] = $this->item($row);
            }
        }

        return ItemLookupResult::fromMatches($skus, $found);
    }

    /**
     * @param list<CustomerDraftDTO> $customers an empty list makes no QBO call
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when a call cannot start before the deadline
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row or created customer lacks an id
     */
    #[Override]
    public function findOrCreateCustomers(#[SensitiveParameter] ApiKeyToken $accessToken, array $customers, Deadline $deadline): CustomerResolutionResult
    {
        return $this->customers->findOrCreate($accessToken, $customers, $deadline);
    }

    /**
     * @param non-empty-list<PostableDocumentDTO> $documents
     *
     * @return non-empty-list<BatchItemOutcomeResult>
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException when the limiter is down before the first send; nothing was sent
     * @throws QuickBooksDeadlineExceededException when the call cannot start before the deadline; nothing was sent
     */
    #[Override]
    public function createDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $documents, Deadline $deadline): array
    {
        return $this->documents->create($accessToken, $documents, $deadline);
    }

    /**
     * @return list<MappingCandidateDTO> active and inactive
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a call past its time limit
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row lacks a name or id, or Preferences lacks its sales form section
     */
    #[Override]
    public function candidates(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity): array
    {
        return $this->mappingCandidates->candidates($accessToken, $entity);
    }

    /**
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a call past its time limit
     * @throws QuickBooksFaultException            when QBO refuses the create
     * @throws UnexpectedApiResultException        when the created entity lacks a name or id
     */
    #[Override]
    public function create(#[SensitiveParameter] ApiKeyToken $accessToken, MappingEntity $entity, string $name): MappingCandidateDTO
    {
        return $this->mappingCandidates->create($accessToken, $entity, $name);
    }

    /**
     * @return array{name: string, id: non-empty-string}
     *
     * @throws UnexpectedApiResultException
     */
    private function item(object $row): array
    {
        if (!$row instanceof IPPItem) {
            throw $this->unexpected('Item query returned another entity');
        }

        $name = self::nonBlank($row->Name);
        $id = self::nonBlank($row->Id);
        if ($name === null || $id === null) {
            throw $this->unexpected('Item name or id blank');
        }

        return ['name' => $name, 'id' => $id];
    }

    /**
     * Takes mixed: the SDK's PHPDoc types are not what its XML parse leaves (a missing field is null; an all-digit one may
     * be an int).
     *
     * @return non-empty-string|null
     */
    private static function nonBlank(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        return \mb_trim((string) $value) !== '' ? (string) $value : null;
    }

    private function unexpected(string $reason): UnexpectedApiResultException
    {
        $this->logger->error('QuickBooks API returned an unexpected result', ['reason' => $reason]);

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $reason);
    }
}
