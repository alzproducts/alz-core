<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\UseCases\UpdateBrandFieldsUseCase;
use App\Application\Contracts\Shopwired\BrandUpdateClientInterface;
use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Domain\Exceptions\UnsupportedFieldException;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateBrandFieldsUseCase::class)]
final class UpdateBrandFieldsUseCaseTest extends TestCase
{
    private BrandUpdateClientInterface&MockInterface $fieldUpdateClient;

    private LoggerInterface&MockInterface $logger;

    private UpdateBrandFieldsUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldUpdateClient = Mockery::mock(BrandUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new UpdateBrandFieldsUseCase(
            $this->fieldUpdateClient,
            $this->logger,
        );
    }

    #[Test]
    public function maps_all_four_fields_and_delegates_to_client(): void
    {
        $brandId = IntId::from(7);

        $this->fieldUpdateClient
            ->shouldReceive('update')
            ->once()
            ->withArgs(static function (int $id, BrandFieldUpdate ...$updates) {
                if ($id !== 7 || \count($updates) !== 4) {
                    return false;
                }

                $byField = [];
                foreach ($updates as $update) {
                    $byField[$update->field->name] = $update->value;
                }

                return $byField === [
                    'Title' => 'Acme',
                    'Description' => 'A great brand',
                    'MetaTitle' => 'SEO Title',
                    'MetaDescription' => 'SEO Description',
                ];
            });

        $this->useCase->execute($brandId, [
            'title' => 'Acme',
            'description' => 'A great brand',
            'meta_title' => 'SEO Title',
            'meta_description' => 'SEO Description',
        ]);
    }

    #[Test]
    public function throws_unsupported_field_exception_on_unknown_field(): void
    {
        $brandId = IntId::from(7);

        $this->fieldUpdateClient->shouldNotReceive('update');

        $this->expectException(UnsupportedFieldException::class);

        $this->useCase->execute($brandId, ['unknown' => 'val']);
    }
}
