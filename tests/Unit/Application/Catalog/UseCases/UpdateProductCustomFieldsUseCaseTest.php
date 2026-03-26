<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\CustomFieldNotFoundException;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateProductCustomFieldsUseCase::class)]
final class UpdateProductCustomFieldsUseCaseTest extends TestCase
{
    private CustomFieldValueFactoryInterface&MockInterface $valueFactory;

    private ProductUpdateClientInterface&MockInterface $productUpdateClient;

    private LoggerInterface&MockInterface $logger;

    private UpdateProductCustomFieldsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->valueFactory = Mockery::mock(CustomFieldValueFactoryInterface::class);
        $this->productUpdateClient = Mockery::mock(ProductUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new UpdateProductCustomFieldsUseCase(
            $this->valueFactory,
            $this->productUpdateClient,
            $this->logger,
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function validates_and_updates_custom_fields(): void
    {
        $productId = IntId::from(99);
        $rawFields = ['colour' => 'Blue', 'notes' => 'Updated'];

        $this->valueFactory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andReturn([]);

        $this->productUpdateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(99, $rawFields);

        $this->useCase->execute($productId, $rawFields);
    }

    // ========================================================================
    // Validation Failures
    // ========================================================================

    #[Test]
    public function throws_validation_failed_exception_for_unknown_field(): void
    {
        $productId = IntId::from(99);
        $rawFields = ['nonexistent' => 'value'];

        $this->valueFactory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andThrow(new CustomFieldNotFoundException('nonexistent', CustomFieldItemType::Product));

        $this->productUpdateClient->shouldNotReceive('updateCustomFields');

        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage("Unknown custom field 'nonexistent' for item type 'product'");

        $this->useCase->execute($productId, $rawFields);
    }

    #[Test]
    public function throws_validation_failed_exception_for_invalid_value(): void
    {
        $productId = IntId::from(99);
        $rawFields = ['release_date' => 'not-a-timestamp'];

        $this->valueFactory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with($rawFields)
            ->andThrow(new InvalidCustomFieldValueException(
                fieldName: 'release_date',
                expectedType: CustomFieldType::Date,
                actualType: 'string',
                rawValue: 'not-a-timestamp',
            ));

        $this->productUpdateClient->shouldNotReceive('updateCustomFields');

        try {
            $this->useCase->execute($productId, $rawFields);
            self::fail('Expected ValidationFailedException');
        } catch (ValidationFailedException $e) {
            self::assertSame(
                "Custom field 'release_date' expected type 'date' but received 'string'",
                $e->reason,
            );
        }
    }
}
