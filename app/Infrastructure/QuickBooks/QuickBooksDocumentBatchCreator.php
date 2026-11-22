<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksInvoiceMapper;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksSalesReceiptMapper;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use SensitiveParameter;
use Webmozart\Assert\Assert;

/**
 * Creates up to one batch call's worth of invoices and sales receipts, never splitting the list (ADR-0019).
 */
final readonly class QuickBooksDocumentBatchCreator
{
    public const int MAX_BATCH_ITEMS = 20;

    public function __construct(
        private QuickBooksTransport $transport,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param non-empty-list<PostableDocumentDTO> $documents
     *
     * @return non-empty-list<BatchItemOutcomeResult> one per document, in input order
     *
     * @throws AuthenticationExpiredException      when the access token is rejected
     * @throws ExternalServiceUnavailableException when the limiter is down before the first send; nothing was sent
     * @throws QuickBooksDeadlineExceededException when the call cannot start before the deadline; nothing was sent
     */
    public function create(#[SensitiveParameter] ApiKeyToken $accessToken, array $documents, Deadline $deadline): array
    {
        Assert::countBetween($documents, 1, self::MAX_BATCH_ITEMS, 'A batch create takes 1 to 20 documents');

        $entities = \array_map(self::entity(...), $documents);
        $execution = $this->transport->executeBatch($accessToken, $entities, $deadline);

        $outcomes = [];
        foreach ($entities as $index => $entity) {
            $batchItemId = (string) ($index + 1);
            $outcome = $execution->outcomeFor($batchItemId, $entity::class);
            $this->logUnlessCreated($batchItemId, $outcome);
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    private static function entity(PostableDocumentDTO $document): IPPInvoice|IPPSalesReceipt
    {
        return $document->draft instanceof InvoiceDraftDTO
            ? QuickBooksInvoiceMapper::toEntity($document->draft, $document->refs)
            : QuickBooksSalesReceiptMapper::toEntity($document->draft, $document->refs);
    }

    private function logUnlessCreated(string $batchItemId, BatchItemOutcomeResult $outcome): void
    {
        if ($outcome->kind === BatchOutcomeKind::Created) {
            return;
        }

        $this->logger->warning('QuickBooks batch item not created', [
            'batch_item_id' => $batchItemId,
            'outcome' => $outcome->kind->value,
            'code' => $outcome->fault?->code,
        ]);
    }
}
