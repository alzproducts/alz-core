<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Domain\Shared\ValueObjects\Deadline;
use Closure;
use Override;
use SensitiveParameter;
use Throwable;

/**
 * A QuickBooks company that holds every item, finds every customer in GBP and posts every document, unless told otherwise.
 */
final class FakeQuickBooksOrderPosting implements QuickBooksOrderPostingInterface
{
    /** @var list<string> method names, in call order */
    public array $calls = [];

    /** @var list<Deadline> one per call */
    public array $deadlines = [];

    /** @var list<string> */
    public array $lookedUp = [];

    /** @var list<CustomerDraftDTO> */
    public array $customers = [];

    /** @var list<PostableDocumentDTO> */
    public array $documents = [];

    /** @var list<string> */
    public array $missingSkus = [];

    /** @var list<ExistingDocumentDTO> */
    public array $existing = [];

    /** @var array<string, string|null> by customer email */
    public array $currencies = [];

    /** @var array<string, CustomerFailureKind|QuickBooksFault> by customer email */
    public array $customerFailures = [];

    /** @var array<string, BatchItemOutcomeResult> by DocNumber */
    public array $batchOutcomes = [];

    /** @var array<string, Throwable> by step: findDocuments, findItems, customerQuery, customerCreate, createDocuments (nothing sent) or afterCreate */
    public array $throws = [];

    /** @var (Closure(): void)|null */
    public ?Closure $duringLookup = null;

    /** @var (Closure(): void)|null */
    public ?Closure $duringCreate = null;

    /**
     * @param list<Sku> $skus
     */
    #[Override]
    public function findItems(#[SensitiveParameter] ApiKeyToken $accessToken, array $skus, Deadline $deadline): ItemLookupResult
    {
        $this->called('findItems', $deadline);
        $this->throwAt('findItems');
        $found = [];
        foreach ($skus as $sku) {
            if ($sku->value !== '' && !\in_array($sku->value, $this->missingSkus, true)) {
                $found[] = ['name' => $sku->value, 'id' => 'item-' . $sku->value];
            }
        }

        return ItemLookupResult::fromMatches($skus, $found);
    }

    /**
     * @param list<string> $docNumbers
     */
    #[Override]
    public function findDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $docNumbers, Deadline $deadline): DocumentLookupResult
    {
        $this->called('findDocuments', $deadline);
        $this->lookedUp = [...$this->lookedUp, ...$docNumbers];
        if ($this->duringLookup !== null) {
            ($this->duringLookup)();
        }
        $this->throwAt('findDocuments');

        return new DocumentLookupResult($this->existing);
    }

    /**
     * @param list<CustomerDraftDTO> $customers
     */
    #[Override]
    public function findOrCreateCustomers(#[SensitiveParameter] ApiKeyToken $accessToken, array $customers, Deadline $deadline): CustomerResolutionResult
    {
        $this->called('findOrCreateCustomers', $deadline);
        $this->throwAt('customerQuery');
        $this->customers = [...$this->customers, ...$customers];
        $this->throwAt('customerCreate');

        return CustomerResolutionResult::of(\array_map($this->resolve(...), $customers));
    }

    /**
     * @param non-empty-list<PostableDocumentDTO> $documents
     *
     * @return non-empty-list<BatchItemOutcomeResult>
     */
    #[Override]
    public function createDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $documents, Deadline $deadline): array
    {
        $this->called('createDocuments', $deadline);
        $this->throwAt('createDocuments');
        $this->documents = [...$this->documents, ...$documents];
        if ($this->duringCreate !== null) {
            ($this->duringCreate)();
        }
        $this->throwAt('afterCreate');

        return \array_map(
            fn(PostableDocumentDTO $document): BatchItemOutcomeResult => $this->batchOutcomes[$document->draft->core->docNumber]
                ?? BatchItemOutcomeResult::created('txn-' . $document->draft->core->docNumber),
            $documents,
        );
    }

    private function resolve(CustomerDraftDTO $customer): CustomerResolutionDTO
    {
        $failure = $this->customerFailures[$customer->email] ?? null;

        return match (true) {
            $failure instanceof QuickBooksFault => CustomerResolutionDTO::failed($customer->displayName, CustomerFailureKind::Faulted, $failure),
            $failure !== null => CustomerResolutionDTO::failed($customer->displayName, $failure),
            default => CustomerResolutionDTO::matched($customer->displayName, new CustomerMatchDTO(
                'customer-1',
                $customer->displayName,
                null,
                \array_key_exists($customer->email, $this->currencies) ? $this->currencies[$customer->email] : 'GBP',
            )),
        };
    }

    private function throwAt(string $step): void
    {
        if (isset($this->throws[$step])) {
            throw $this->throws[$step];
        }
    }

    private function called(string $method, Deadline $deadline): void
    {
        $this->calls[] = $method;
        $this->deadlines[] = $deadline;
    }
}
