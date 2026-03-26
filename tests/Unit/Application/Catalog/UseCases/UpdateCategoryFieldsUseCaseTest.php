<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\UpdateCategoryFieldsUseCase;
use App\Application\Contracts\Shopwired\CategoryUpdateClientInterface;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Domain\Exceptions\UnsupportedFieldException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateCategoryFieldsUseCase::class)]
final class UpdateCategoryFieldsUseCaseTest extends TestCase
{
    private CategoryUpdateClientInterface&MockInterface $fieldUpdateClient;

    private LoggerInterface&MockInterface $logger;

    private UpdateCategoryFieldsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(CategoryUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new UpdateCategoryFieldsUseCase(
            $this->fieldUpdateClient,
            $this->logger,
        );
    }

    #[Test]
    public function maps_all_four_fields_and_delegates_to_client(): void
    {
        $categoryId = IntId::from(3);

        $this->fieldUpdateClient
            ->shouldReceive('update')
            ->once()
            ->withArgs(static function (int $id, CategoryFieldUpdate ...$updates) {
                if ($id !== 3 || \count($updates) !== 4) {
                    return false;
                }

                $byField = [];
                foreach ($updates as $update) {
                    $byField[$update->field->name] = $update->value;
                }

                return $byField === [
                    'Title' => 'Electronics',
                    'Description' => 'All electronic goods',
                    'MetaTitle' => 'SEO Title',
                    'MetaDescription' => 'SEO Description',
                ];
            });

        $this->useCase->execute($categoryId, [
            'title' => 'Electronics',
            'description' => 'All electronic goods',
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
        ]);
    }

    #[Test]
    public function throws_unsupported_field_exception_on_unknown_field(): void
    {
        $categoryId = IntId::from(3);

        $this->fieldUpdateClient->shouldNotReceive('update');

        $this->expectException(UnsupportedFieldException::class);

        $this->useCase->execute($categoryId, ['unknown' => 'val']);
    }
}
