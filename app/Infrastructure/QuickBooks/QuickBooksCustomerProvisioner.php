<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksCustomerMapper;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPCustomer;
use SensitiveParameter;

/**
 * Finds customers by display name across active and inactive ones, then creates each miss one at a time. Every query
 * runs before any create, so a create never races a name the same call has yet to look up.
 */
final readonly class QuickBooksCustomerProvisioner
{
    public function __construct(
        private QuickBooksTransport $transport,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<CustomerDraftDTO> $customers
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException            when QBO rejects a query
     * @throws UnexpectedApiResultException
     */
    public function findOrCreate(#[SensitiveParameter] ApiKeyToken $accessToken, array $customers, Deadline $deadline): CustomerResolutionResult
    {
        $drafts = self::distinct($customers);
        $found = $this->find($accessToken, \array_map(static fn(CustomerDraftDTO $draft): string => $draft->displayName, $drafts), $deadline);

        $resolutions = [];
        foreach ($drafts as $draft) {
            $row = $found[self::key($draft->displayName)] ?? null;
            $resolutions[] = $row === null
                ? $this->create($accessToken, $draft, $deadline)
                : $this->resolved($draft->displayName, $row);
        }

        return CustomerResolutionResult::of($resolutions);
    }

    /**
     * @param list<CustomerDraftDTO> $customers
     *
     * @return list<CustomerDraftDTO> the first draft per folded display name
     */
    private static function distinct(array $customers): array
    {
        $distinct = [];
        foreach ($customers as $customer) {
            $distinct[self::key($customer->displayName)] ??= $customer;
        }

        return \array_values($distinct);
    }

    /**
     * @param list<string> $displayNames
     *
     * @return array<array-key, IPPCustomer> the first row per folded display name
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function find(#[SensitiveParameter] ApiKeyToken $accessToken, array $displayNames, Deadline $deadline): array
    {
        $found = [];
        foreach (QuickBooksQueryEscaper::chunks(\array_map(\mb_trim(...), $displayNames)) as $chunk) {
            $query = 'SELECT * FROM Customer WHERE Active IN (true, false) AND DisplayName IN '
                . QuickBooksQueryEscaper::inList($chunk)
                . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS;

            foreach ($this->transport->query($accessToken, $query, new QuickBooksCallSpec(QuickBooksCall::CustomerLookup, $deadline)) as $row) {
                if (!$row instanceof IPPCustomer) {
                    throw $this->unexpected('Customer query returned another entity');
                }

                $found[self::key(self::text($row->DisplayName) ?? '')] ??= $row;
            }
        }

        return $found;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException            when the re-query after a duplicate-name Fault is rejected
     * @throws UnexpectedApiResultException
     */
    private function create(#[SensitiveParameter] ApiKeyToken $accessToken, CustomerDraftDTO $draft, Deadline $deadline): CustomerResolutionDTO
    {
        try {
            $created = $this->transport->add($accessToken, QuickBooksCustomerMapper::toEntity($draft), IPPCustomer::class, new QuickBooksCallSpec(QuickBooksCall::CustomerCreate, $deadline));
        } catch (QuickBooksFaultException $e) { // @ignoreException - a create's Fault is this name's outcome, not the call's
            return $e->fault->kind === QuickBooksFaultKind::DuplicateCustomerName
                ? $this->afterDuplicateName($accessToken, $draft->displayName, $e->fault, $deadline)
                : CustomerResolutionDTO::failed($draft->displayName, CustomerFailureKind::Faulted, $e->fault);
        }

        return $this->resolved($draft->displayName, $created);
    }

    /**
     * QBO also refuses a name a vendor or employee holds, or one created since the first query: look the name up once more.
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function afterDuplicateName(
        #[SensitiveParameter]
        ApiKeyToken $accessToken,
        string $displayName,
        QuickBooksFault $fault,
        Deadline $deadline,
    ): CustomerResolutionDTO {
        $row = $this->find($accessToken, [$displayName], $deadline)[self::key($displayName)] ?? null;

        return $row === null
            ? CustomerResolutionDTO::failed($displayName, CustomerFailureKind::NameClash, $fault)
            : $this->resolved($displayName, $row);
    }

    /**
     * @throws UnexpectedApiResultException when the customer has no id
     */
    private function resolved(string $displayName, IPPCustomer $customer): CustomerResolutionDTO
    {
        if (self::inactive($customer->Active)) {
            return CustomerResolutionDTO::failed($displayName, CustomerFailureKind::Inactive);
        }

        $id = self::text($customer->Id);
        if ($id === null) {
            throw $this->unexpected('Customer id blank');
        }

        return CustomerResolutionDTO::matched($displayName, new CustomerMatchDTO(
            id: $id,
            displayName: self::text($customer->DisplayName) ?? $displayName,
            salesTermRef: self::reference($customer->SalesTermRef),
            currency: self::currency($customer->CurrencyRef),
        ));
    }

    /**
     * intentional: not `reference()`, which reads a value of an unexpected shape as absent; here that is ''.
     */
    private static function currency(mixed $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $value = \is_object($reference) && \property_exists($reference, 'value') ? $reference->value : $reference;

        return \is_string($value) ? \mb_trim($value) : '';
    }

    /**
     * Trimmed, because a returned row's DisplayName is read trimmed: an order name with edge spaces must still find it.
     */
    private static function key(string $displayName): string
    {
        return QuickBooksQueryEscaper::fold(\mb_trim($displayName));
    }

    /**
     * Takes mixed: the XML parse leaves the string `false`, where the PHPDoc promises a boolean.
     */
    private static function inactive(mixed $active): bool
    {
        return $active === false || $active === 'false';
    }

    /**
     * Takes mixed: the PHPDoc names a class that does not exist; the parse leaves the id as a string, or an object
     * holding it in `value`.
     *
     * @return non-empty-string|null
     */
    private static function reference(mixed $reference): ?string
    {
        return self::text(\is_object($reference) && \property_exists($reference, 'value') ? $reference->value : $reference);
    }

    /**
     * Takes mixed: the SDK's PHPDoc types are not what its XML parse leaves (strings, ints, null or reference objects).
     *
     * @return non-empty-string|null
     */
    private static function text(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $text = \mb_trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function unexpected(string $reason): UnexpectedApiResultException
    {
        $this->logger->error('QuickBooks API returned an unexpected customer result', ['reason' => $reason]);

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $reason);
    }
}
