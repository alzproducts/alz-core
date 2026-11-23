<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use Webmozart\Assert\Assert;

/**
 * A document draft with every ref it posts against. Callers check `ResolvedDocumentRefsDTO::missingFor()` first.
 */
final readonly class PostableDocumentDTO
{
    public function __construct(
        public InvoiceDraftDTO|SalesReceiptDraftDTO $draft,
        public ResolvedDocumentRefsDTO $refs,
    ) {
        Assert::isEmpty($refs->missingFor($draft), 'A postable document needs every ref its draft names');
    }
}
