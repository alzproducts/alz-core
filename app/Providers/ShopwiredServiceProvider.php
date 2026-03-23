<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Contracts\Shopwired\BrandWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\BrandWebhookParserInterface;
use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookParserInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\FilterGroupClientInterface;
use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\OrderWebhookParserInterface;
use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileBulkSaleStateUseCase;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileProductSaleStateUseCase;
use App\Application\Shopwired\UseCases\Webhooks\CreateOrderRefundUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncBrandUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCategoryUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCustomerUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncOrderUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateOrderStatusUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateProductStockUseCase;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Shopwired\Clients\BasicProductUpdateClient;
use App\Infrastructure\Shopwired\Clients\BrandFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\CategoryFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\CustomerFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\ProductClient;
use App\Infrastructure\Shopwired\Clients\ProductFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\ProductUpdateClient;
use App\Infrastructure\Shopwired\Dispatchers\QueuedSaleReconciliationDispatcher;
use App\Infrastructure\Shopwired\Dispatchers\QueuedShopwiredSyncDispatcher;
use App\Infrastructure\Shopwired\Factories\ProductCustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use App\Infrastructure\Shopwired\Mappers\ProductModelMapper;
use App\Infrastructure\Shopwired\Parsers\ShopwiredBrandWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredCategoryWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredCustomerWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredOrderWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredProductWebhookParser;
use App\Infrastructure\Shopwired\Repositories\EloquentBrandRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCategoryRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomerRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomFieldRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentFilterGroupRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentOrderRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredBrandWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredCategoryWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredCustomerWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredOrderWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredProductWebhookEventResolver;
use App\Infrastructure\Shopwired\Services\EloquentWebhookIdempotencyService;
use App\Infrastructure\Shopwired\Services\ProductIdentifierResolver;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * ShopWired API Client
 *
 * Deferred provider for ShopWired endpoint clients - only loads when requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * Architecture: All endpoint clients share a single ShopwiredHttpTransport
 * instance managed by the factory (lazy singleton pattern).
 *
 * @template-pattern API Client Service Provider
 */
final class ShopwiredServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerClients();
        $this->registerRepositories();
        $this->registerFactories();
        $this->registerDispatchers();
        $this->registerWebhookServices();
        $this->registerWebhookBindings();
        $this->registerSaleManagementBindings();
    }

    private function registerClients(): void
    {
        $this->app->singleton(ConnectivityClientInterface::class, static fn(): ConnectivityClientInterface => ShopwiredClientFactory::createConnectivityClient());
        $this->app->singleton(BrandClientInterface::class, static fn(): BrandClientInterface => ShopwiredClientFactory::createBrandClient());
        $this->app->singleton(CategoryClientInterface::class, static fn(): CategoryClientInterface => ShopwiredClientFactory::createCategoryClient());
        $this->app->singleton(CustomFieldClientInterface::class, static fn(): CustomFieldClientInterface => ShopwiredClientFactory::createCustomFieldClient());
        $this->app->singleton(FilterGroupClientInterface::class, static fn(): FilterGroupClientInterface => ShopwiredClientFactory::createFilterGroupClient());
        $this->app->singleton(CustomerClientInterface::class, static fn(): CustomerClientInterface => ShopwiredClientFactory::createCustomerClient());
        $this->app->singleton(OrderClientInterface::class, static fn(): OrderClientInterface => ShopwiredClientFactory::createOrderClient());
        $this->app->singleton(StockClientInterface::class, static fn(): StockClientInterface => ShopwiredClientFactory::createStockClient());
        $this->app->singleton(PriceUpdateClientInterface::class, static fn(): PriceUpdateClientInterface => ShopwiredClientFactory::createPriceUpdateClient());

        // Scoped: depends on scoped ProductDomainFactory (Octane isolation)
        $this->app->scoped(
            ProductClientInterface::class,
            static fn(Application $app): ProductClientInterface => new ProductClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductDomainFactory::class),
            ),
        );

        // Scoped: depends on scoped ProductClientInterface
        $this->app->scoped(
            ProductUpdateClientInterface::class,
            static fn(Application $app): ProductUpdateClientInterface => new ProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductClientInterface::class),
            ),
        );

        // Scoped: depends on scoped ProductRepositoryInterface
        $this->app->scoped(
            BasicProductUpdateClientInterface::class,
            static fn(Application $app): BasicProductUpdateClientInterface => new BasicProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductRepositoryInterface::class),
            ),
        );

        $this->app->singleton(WebhookClientInterface::class, static fn(): WebhookClientInterface => ShopwiredClientFactory::createWebhookClient());

        // FieldUpdate clients — simple PUT field updates per entity
        $this->app->singleton(ProductFieldUpdateClientInterface::class, static fn(): ProductFieldUpdateClientInterface => new ProductFieldUpdateClient(ShopwiredClientFactory::getTransport()));
        $this->app->singleton(CustomerFieldUpdateClientInterface::class, static fn(): CustomerFieldUpdateClientInterface => new CustomerFieldUpdateClient(ShopwiredClientFactory::getTransport()));
        $this->app->singleton(CategoryFieldUpdateClientInterface::class, static fn(): CategoryFieldUpdateClientInterface => new CategoryFieldUpdateClient(ShopwiredClientFactory::getTransport()));
        $this->app->singleton(BrandFieldUpdateClientInterface::class, static fn(): BrandFieldUpdateClientInterface => new BrandFieldUpdateClient(ShopwiredClientFactory::getTransport()));
    }

    private function registerRepositories(): void
    {
        $this->app->singleton(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->singleton(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
        $this->app->singleton(CustomFieldRepositoryInterface::class, EloquentCustomFieldRepository::class);
        $this->app->singleton(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
        $this->app->singleton(BrandRepositoryInterface::class, EloquentBrandRepository::class);
        $this->app->singleton(FilterGroupRepositoryInterface::class, EloquentFilterGroupRepository::class);
        $this->app->singleton(ProductIdentifierResolverInterface::class, ProductIdentifierResolver::class);

        // Scoped: fresh mapper per queue job (Octane isolation)
        $this->app->scoped(ProductRepositoryInterface::class, EloquentProductRepository::class);
    }

    private function registerFactories(): void
    {
        // All scoped to prevent stale state in Octane
        $this->app->scoped(ProductDomainFactory::class);
        $this->app->scoped(ProductCustomFieldFactory::class);
        $this->app->scoped(ProductFilterFactory::class);
        $this->app->scoped(ProductModelMapper::class);
    }

    private function registerWebhookServices(): void
    {
        // Event resolvers — map topic strings to domain intents (stateless)
        $this->app->singleton(OrderWebhookEventResolverInterface::class, ShopwiredOrderWebhookEventResolver::class);
        $this->app->singleton(ProductWebhookEventResolverInterface::class, ShopwiredProductWebhookEventResolver::class);
        $this->app->singleton(CustomerWebhookEventResolverInterface::class, ShopwiredCustomerWebhookEventResolver::class);
        $this->app->singleton(CategoryWebhookEventResolverInterface::class, ShopwiredCategoryWebhookEventResolver::class);
        $this->app->singleton(BrandWebhookEventResolverInterface::class, ShopwiredBrandWebhookEventResolver::class);

        // Parsers — stateless → singleton, except product (scoped via ProductDomainFactory)
        $this->app->singleton(OrderWebhookParserInterface::class, ShopwiredOrderWebhookParser::class);
        $this->app->singleton(CustomerWebhookParserInterface::class, ShopwiredCustomerWebhookParser::class);
        $this->app->singleton(CategoryWebhookParserInterface::class, ShopwiredCategoryWebhookParser::class);
        $this->app->singleton(BrandWebhookParserInterface::class, ShopwiredBrandWebhookParser::class);
        $this->app->scoped(ProductWebhookParserInterface::class, ShopwiredProductWebhookParser::class);

        $this->app->singleton(WebhookIdempotencyServiceInterface::class, EloquentWebhookIdempotencyService::class);
    }

    private function registerDispatchers(): void
    {
        $this->app->singleton(ShopwiredSyncDispatcherInterface::class, QueuedShopwiredSyncDispatcher::class);
        $this->app->singleton(SaleReconciliationDispatcherInterface::class, QueuedSaleReconciliationDispatcher::class);
    }

    private function registerSaleManagementBindings(): void
    {
        $this->app->when([
            ReconcileProductSaleStateUseCase::class,
            ReconcileBulkSaleStateUseCase::class,
            ProductSaleStateResolver::class,
        ])->needs('$saleCategoryId')
            ->give(static function (): int {
                $value = \config('shopwired.sale_category_id');

                if (! \is_numeric($value)) {
                    throw new InvalidConfigurationException(
                        'shopwired.sale_category_id',
                        'shopwired.sale_category_id must be a numeric value',
                    );
                }

                return (int) $value;
            });
    }

    private function registerWebhookBindings(): void
    {
        $this->app->when([
            SyncProductUseCase::class,
            SyncOrderUseCase::class,
            SyncCustomerUseCase::class,
            SyncCategoryUseCase::class,
            SyncBrandUseCase::class,
            UpdateProductStockUseCase::class,
            UpdateOrderStatusUseCase::class,
            CreateOrderRefundUseCase::class,
        ])->needs('$webhookStalenessHours')
            ->give(static function (): int {
                $value = \config('shopwired.webhook_staleness_hours');

                if (! \is_numeric($value)) {
                    throw new InvalidConfigurationException(
                        'shopwired.webhook_staleness_hours',
                        'shopwired.webhook_staleness_hours must be a numeric value',
                    );
                }

                return (int) $value;
            });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            BasicProductUpdateClientInterface::class,
            BrandClientInterface::class,
            BrandFieldUpdateClientInterface::class,
            BrandRepositoryInterface::class,
            BrandWebhookEventResolverInterface::class,
            BrandWebhookParserInterface::class,
            CategoryClientInterface::class,
            CategoryFieldUpdateClientInterface::class,
            CategoryRepositoryInterface::class,
            CategoryWebhookEventResolverInterface::class,
            CategoryWebhookParserInterface::class,
            ConnectivityClientInterface::class,
            CreateOrderRefundUseCase::class,
            CustomFieldClientInterface::class,
            CustomFieldRepositoryInterface::class,
            CustomerClientInterface::class,
            CustomerFieldUpdateClientInterface::class,
            CustomerRepositoryInterface::class,
            CustomerWebhookEventResolverInterface::class,
            CustomerWebhookParserInterface::class,
            FilterGroupClientInterface::class,
            FilterGroupRepositoryInterface::class,
            OrderClientInterface::class,
            OrderRepositoryInterface::class,
            OrderWebhookEventResolverInterface::class,
            OrderWebhookParserInterface::class,
            ProductClientInterface::class,
            ProductCustomFieldFactory::class,
            ProductFieldUpdateClientInterface::class,
            ProductDomainFactory::class,
            ProductFilterFactory::class,
            PriceUpdateClientInterface::class,
            ProductIdentifierResolverInterface::class,
            ProductModelMapper::class,
            ProductRepositoryInterface::class,
            ProductUpdateClientInterface::class,
            ProductWebhookEventResolverInterface::class,
            ProductWebhookParserInterface::class,
            SaleReconciliationDispatcherInterface::class,
            ShopwiredSyncDispatcherInterface::class,
            StockClientInterface::class,
            SyncBrandUseCase::class,
            SyncCategoryUseCase::class,
            SyncCustomerUseCase::class,
            SyncOrderUseCase::class,
            SyncProductUseCase::class,
            UpdateOrderStatusUseCase::class,
            UpdateProductStockUseCase::class,
            WebhookClientInterface::class,
            WebhookIdempotencyServiceInterface::class,
        ];
    }
}
