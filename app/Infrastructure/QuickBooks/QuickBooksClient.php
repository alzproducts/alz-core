<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\Shared\ValueObjects\Deadline;
use Override;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use SensitiveParameter;

final readonly class QuickBooksClient implements QuickBooksClientInterface, QuickBooksOrderPostingInterface
{
    private const array DOCUMENT_ENTITIES = [
        'Invoice' => DocType::Invoice,
        'SalesReceipt' => DocType::SalesReceipt,
    ];

    public function __construct(
        private QuickBooksTransport $transport,
        private QuickBooksCustomerProvisioner $customers,
        private QuickBooksDocumentBatchCreator $documents,
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
     * @param list<string> $docNumbers an empty list makes no QBO call
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when a query cannot start before the deadline
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException        when a row lacks a document number or id, or a query hits the row limit
     */
    #[Override]
    public function findDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $docNumbers, Deadline $deadline): DocumentLookupResult
    {
        $found = [];
        foreach (QuickBooksQueryEscaper::chunks(\array_map(\mb_trim(...), $docNumbers)) as $chunk) {
            foreach (self::DOCUMENT_ENTITIES as $entity => $type) {
                $query = "SELECT Id, DocNumber FROM {$entity} WHERE DocNumber IN "
                    . QuickBooksQueryEscaper::inList($chunk)
                    . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS;

                $rows = $this->transport->query($accessToken, $query, $deadline);
                if (\count($rows) >= QuickBooksQueryEscaper::MAX_RESULTS) {
                    throw $this->unexpected('Document query hit the row limit');
                }

                foreach ($rows as $row) {
                    $found[] = $this->document($row, $type);
                }
            }
        }

        return new DocumentLookupResult($found);
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
     * @throws UnexpectedApiResultException
     */
    private function document(object $row, DocType $type): ExistingDocumentDTO
    {
        $rowType = match (true) {
            $row instanceof IPPInvoice => DocType::Invoice,
            $row instanceof IPPSalesReceipt => DocType::SalesReceipt,
            default => null,
        };
        if ($rowType !== $type) {
            throw $this->unexpected('Document query returned another entity');
        }

        $docNumber = self::nonBlank($row->DocNumber ?? null);
        $id = self::nonBlank($row->Id ?? null);
        if ($docNumber === null || $id === null) {
            throw $this->unexpected('Document number or id blank');
        }

        return new ExistingDocumentDTO($docNumber, $id, $type);
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
