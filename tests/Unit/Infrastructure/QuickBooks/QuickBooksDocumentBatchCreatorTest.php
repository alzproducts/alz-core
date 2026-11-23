<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksBatchExecution;
use App\Infrastructure\QuickBooks\QuickBooksDocumentBatchCreator;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use QuickBooksOnline\API\DataService\IntuitBatchResponse;
use QuickBooksOnline\API\Exception\ValidationException;
use Tests\Unit\Application\QuickBooks\OrderSync\DocumentDraftFixture;

#[CoversClass(QuickBooksDocumentBatchCreator::class)]
final class QuickBooksDocumentBatchCreatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_maps_each_draft_by_type_and_returns_outcomes_in_input_order(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('executeBatch')->once()
            ->with(Mockery::any(), Mockery::on(static fn(array $entities): bool => \count($entities) === 3
                && $entities[0] instanceof IPPInvoice
                && $entities[1] instanceof IPPSalesReceipt
                && $entities[2] instanceof IPPInvoice), Mockery::any())
            ->andReturn(QuickBooksBatchExecution::responses([
                '1' => self::created(new IPPInvoice(), '101'),
                '2' => self::faulted(),
                '3' => self::created(new IPPInvoice(), '103'),
            ], 'tid-batch'));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()
            ->with('QuickBooks batch item not created', ['batch_item_id' => '2', 'outcome' => BatchOutcomeKind::Faulted->value, 'code' => '6140', 'intuit_tid' => 'tid-batch']);

        $outcomes = new QuickBooksDocumentBatchCreator($transport, $logger)->create(new ApiKeyToken('access'), [self::invoice(), self::receipt(), self::invoice()], self::deadline());

        $this->assertSame(
            [[BatchOutcomeKind::Created, '101'], [BatchOutcomeKind::Faulted, null], [BatchOutcomeKind::Created, '103']],
            \array_map(static fn(BatchItemOutcomeResult $outcome): array => [$outcome->kind, $outcome->qboId], $outcomes),
        );
    }

    #[Test]
    public function an_item_answered_with_the_wrong_entity_type_is_ambiguous(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('executeBatch')->once()->andReturn(QuickBooksBatchExecution::responses(['1' => self::created(new IPPSalesReceipt(), '101')]));

        $outcomes = new QuickBooksDocumentBatchCreator($transport, new NullLogger())->create(new ApiKeyToken('access'), [self::invoice()], self::deadline());

        $this->assertSame(BatchOutcomeKind::Ambiguous, $outcomes[0]->kind);
    }

    #[Test]
    public function every_item_not_created_is_logged_with_the_call_s_intuit_tid_or_null(): void
    {
        foreach (['tid-batch', null] as $intuitTid) {
            $transport = Mockery::mock(QuickBooksTransport::class);
            $transport->shouldReceive('executeBatch')->once()->andReturn(QuickBooksBatchExecution::responses(['1' => self::faulted()], $intuitTid));
            $logger = new RecordingLogger();

            new QuickBooksDocumentBatchCreator($transport, $logger)->create(new ApiKeyToken('access'), [self::invoice(), self::receipt()], self::deadline());

            $this->assertSame([
                ['QuickBooks batch item not created', ['batch_item_id' => '1', 'outcome' => BatchOutcomeKind::Faulted->value, 'code' => '6140', 'intuit_tid' => $intuitTid]],
                ['QuickBooks batch item not created', ['batch_item_id' => '2', 'outcome' => BatchOutcomeKind::Ambiguous->value, 'code' => null, 'intuit_tid' => $intuitTid]],
            ], $logger->records);
        }
    }

    #[Test]
    public function more_than_twenty_documents_are_refused_before_any_call(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldNotReceive('executeBatch');

        $this->expectException(InvalidArgumentException::class);

        new QuickBooksDocumentBatchCreator($transport, new NullLogger())->create(new ApiKeyToken('access'), self::receipts(21), self::deadline());
    }

    #[Test]
    public function twenty_documents_go_in_one_call(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('executeBatch')->once()->andReturn(QuickBooksBatchExecution::responses([]));

        $this->assertCount(20, new QuickBooksDocumentBatchCreator($transport, new NullLogger())->create(new ApiKeyToken('access'), self::receipts(20), self::deadline()));
    }

    /**
     * @return non-empty-list<PostableDocumentDTO>
     */
    private static function receipts(int $count): array
    {
        $documents = [self::receipt()];
        for ($i = 1; $i < $count; $i++) {
            $documents[] = self::receipt();
        }

        return $documents;
    }

    private static function invoice(): PostableDocumentDTO
    {
        return new PostableDocumentDTO(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs());
    }

    private static function receipt(): PostableDocumentDTO
    {
        return new PostableDocumentDTO(DocumentDraftFixture::receipt(), DocumentDraftFixture::refs());
    }

    private static function created(IPPInvoice|IPPSalesReceipt $entity, string $id): IntuitBatchResponse
    {
        $entity->Id = $id;
        $response = new IntuitBatchResponse();
        $response->entity = $entity;
        $response->successFlagOn();

        return $response;
    }

    private static function faulted(): IntuitBatchResponse
    {
        $response = new IntuitBatchResponse();
        $response->exception = new ValidationException('Duplicate Document Number Error', 6140);

        return $response;
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), 30);
    }
}
