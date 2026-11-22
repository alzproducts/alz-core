<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\QuickBooks\OrderSync\DocumentDraftFixture;

#[CoversClass(ResolvedDocumentRefsDTO::class)]
#[CoversClass(PostableDocumentDTO::class)]
final class ResolvedDocumentRefsDTOTest extends TestCase
{
    #[Test]
    public function complete_refs_miss_nothing(): void
    {
        $refs = DocumentDraftFixture::refs();

        $this->assertSame([], $refs->missingFor(DocumentDraftFixture::invoice()));
        $this->assertSame([], $refs->missingFor(DocumentDraftFixture::receipt()));
    }

    #[Test]
    public function it_names_each_missing_line_ref_once(): void
    {
        $refs = DocumentDraftFixture::refs(skus: ['SKU-B'], taxCodes: [TaxKey::Zero->value => 'tax-zero']);

        $this->assertSame(['item:SKU-A', 'tax_code:standard'], $refs->missingFor(DocumentDraftFixture::invoice()));
    }

    #[Test]
    public function it_names_the_fixed_refs_each_document_type_needs(): void
    {
        $refs = DocumentDraftFixture::refs(fixedRefs: []);

        $this->assertSame(['fixed_ref:department', 'fixed_ref:class'], $refs->missingFor(DocumentDraftFixture::invoice()));
        $this->assertSame(['fixed_ref:department'], $refs->missingFor(DocumentDraftFixture::receipt()));
    }

    #[Test]
    public function an_invoice_needs_terms_and_the_po_number_field(): void
    {
        $refs = DocumentDraftFixture::refs(['termsId' => null, 'poNumberFieldId' => null, 'transactionIdFieldId' => null]);

        $this->assertSame(['terms', 'po_number_field'], $refs->missingFor(DocumentDraftFixture::invoice()));
    }

    #[Test]
    public function a_paid_receipt_needs_the_transaction_field_payment_method_and_deposit_account(): void
    {
        $refs = DocumentDraftFixture::refs(['paymentMethodId' => null, 'depositAccountId' => null, 'transactionIdFieldId' => null, 'termsId' => null]);

        $this->assertSame(['transaction_id_field', 'payment_method', 'deposit_account'], $refs->missingFor(DocumentDraftFixture::receipt()));
        $this->assertSame(['transaction_id_field'], $refs->missingFor(DocumentDraftFixture::receipt(paymentKey: null)));
    }

    #[Test]
    public function it_reads_ids_by_key(): void
    {
        $refs = DocumentDraftFixture::refs();

        $this->assertSame('tax-std', $refs->taxCodeIdFor(TaxKey::Standard));
        $this->assertNull($refs->taxCodeIdFor(TaxKey::EuGoods));
        $this->assertSame('class-1', $refs->fixedRefIdFor(FixedRefKey::SalesClass));
        $this->assertNull($refs->fixedRefIdFor(FixedRefKey::DefaultTerms));
    }

    #[Test]
    public function it_needs_a_customer_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResolvedDocumentRefsDTO('', ItemLookupResult::fromMatches([], []), [], []);
    }

    #[Test]
    public function a_postable_document_rejects_incomplete_refs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PostableDocumentDTO(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs(['termsId' => null]));
    }

    #[Test]
    public function a_postable_document_holds_its_draft_and_refs(): void
    {
        $draft = DocumentDraftFixture::receipt();
        $refs = DocumentDraftFixture::refs();

        $document = new PostableDocumentDTO($draft, $refs);

        $this->assertSame([$draft, $refs], [$document->draft, $document->refs]);
    }
}
