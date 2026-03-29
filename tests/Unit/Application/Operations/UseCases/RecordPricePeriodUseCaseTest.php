<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Operations\UseCases;

use App\Application\Contracts\Operations\PricePeriodRepositoryInterface;
use App\Application\Operations\UseCases\RecordPricePeriodUseCase;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Operations\ValueObjects\PriceSnapshot;
use App\Domain\Shared\Money\ValueObjects\Money;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(RecordPricePeriodUseCase::class)]
final class RecordPricePeriodUseCaseTest extends TestCase
{
    private PricePeriodRepositoryInterface&MockInterface $repo;

    private RecordPricePeriodUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(PricePeriodRepositoryInterface::class);
        $this->useCase = new RecordPricePeriodUseCase($this->repo);
    }

    #[Test]
    public function decomposes_pricing_with_active_sale_into_correct_scalars(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(29.99),
            salePrice: Money::inclusive(19.99),
        );

        $this->repo->shouldReceive('recordPriceChange')
            ->once()
            ->with(Mockery::on(static fn(PriceSnapshot $s): bool => $s->sku->value === 'TEST-001'
                && $s->basePriceGross === 29.99
                && $s->salePriceGross === 19.99
                && $s->effectivePriceGross === 19.99
                && $s->priceHasTax === true));

        $this->useCase->execute(Sku::fromTrusted('TEST-001'), $pricing);
    }

    #[Test]
    public function decomposes_pricing_without_sale_into_correct_scalars(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(29.99),
            salePrice: null,
        );

        $this->repo->shouldReceive('recordPriceChange')
            ->once()
            ->with(Mockery::on(static fn(PriceSnapshot $s): bool => $s->sku->value === 'TEST-001'
                && $s->basePriceGross === 29.99
                && $s->salePriceGross === null
                && $s->effectivePriceGross === 29.99
                && $s->priceHasTax === true));

        $this->useCase->execute(Sku::fromTrusted('TEST-001'), $pricing);
    }

    #[Test]
    public function zero_rated_product_reports_no_tax(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::zeroRated(20.00),
        );

        $this->repo->shouldReceive('recordPriceChange')
            ->once()
            ->with(Mockery::on(static fn(PriceSnapshot $s): bool => $s->sku->value === 'TEST-001'
                && $s->basePriceGross === 20.0
                && $s->salePriceGross === null
                && $s->effectivePriceGross === 20.0
                && $s->priceHasTax === false));

        $this->useCase->execute(Sku::fromTrusted('TEST-001'), $pricing);
    }

    #[Test]
    public function database_exception_bubbles_through(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
        );

        $this->repo->shouldReceive('recordPriceChange')
            ->once()
            ->andThrow(new DatabaseOperationFailedException('insert', 'connection failed'));

        $this->expectException(DatabaseOperationFailedException::class);

        $this->useCase->execute(Sku::fromTrusted('TEST-001'), $pricing);
    }
}
