<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\UpdateProductFieldsUseCase;
use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Domain\Catalog\Product\Enums\ProductUpdatableField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\UnsupportedFieldException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateProductFieldsUseCase::class)]
final class UpdateProductFieldsUseCaseTest extends TestCase
{
    private ProductFieldUpdateClientInterface&MockInterface $fieldUpdateClient;

    private LoggerInterface&MockInterface $logger;

    private UpdateProductFieldsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(ProductFieldUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new UpdateProductFieldsUseCase(
            $this->fieldUpdateClient,
            $this->logger,
        );
    }

    #[Test]
    public function maps_string_fields_and_delegates_to_client(): void
    {
        $productId = IntId::from(42);

        $this->fieldUpdateClient
            ->shouldReceive('update')
            ->once()
            ->withArgs(static function (int $id, ProductFieldUpdate ...$updates) {
                if ($id !== 42) {
                    return false;
                }

                $byField = [];
                foreach ($updates as $update) {
                    $byField[$update->field->name] = $update->value;
                }

                return $byField === [
                    'Title' => 'My Product',
                    'Description' => 'A description',
                ];
            });

        $this->useCase->execute($productId, [
            'title' => 'My Product',
            'description' => 'A description',
        ]);
    }

    #[Test]
    public function maps_categories_field_to_product_field_update(): void
    {
        $productId = IntId::from(10);

        $this->fieldUpdateClient
            ->shouldReceive('update')
            ->once()
            ->withArgs(static function (int $id, ProductFieldUpdate ...$updates) {
                if ($id !== 10 || \count($updates) !== 1) {
                    return false;
                }

                return $updates[0]->field === ProductUpdatableField::Categories
                    && $updates[0]->value === [1, 2];
            });

        $this->useCase->execute($productId, ['categories' => [1, 2]]);
    }

    #[Test]
    public function maps_sort_order_field_to_product_field_update(): void
    {
        $productId = IntId::from(10);

        $this->fieldUpdateClient
            ->shouldReceive('update')
            ->once()
            ->withArgs(static function (int $id, ProductFieldUpdate ...$updates) {
                if ($id !== 10 || \count($updates) !== 1) {
                    return false;
                }

                return $updates[0]->field === ProductUpdatableField::SortOrder
                    && $updates[0]->value === 5;
            });

        $this->useCase->execute($productId, ['sort_order' => 5]);
    }

    #[Test]
    public function throws_unsupported_field_exception_on_unknown_field(): void
    {
        $productId = IntId::from(10);

        $this->fieldUpdateClient->shouldNotReceive('update');

        $this->expectException(UnsupportedFieldException::class);

        $this->useCase->execute($productId, ['unknown' => 'val']);
    }
}
