<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Catalog\UseCases\UpdateBrandCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateCategoryCustomFieldsUseCase;
use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Contracts\Shopwired\BrandUpdateClientInterface;
use App\Application\Contracts\Shopwired\BrandWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\BrandWebhookParserInterface;
use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\CategoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CategoryWebhookParserInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldValueFactoryInterface;
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
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Application\Shopwired\SaleManagement\UseCases\AddProductToSaleUseCase;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileBulkSaleStateUseCase;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileProductSaleStateUseCase;
use App\Application\Shopwired\SaleManagement\UseCases\RemoveProductFromSaleUseCase;
use App\Application\Shopwired\UseCases\Webhooks\CreateOrderRefundUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncBrandUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCategoryUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCustomerUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncOrderUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateOrderStatusUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateProductStockUseCase;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Catalog\Brand\Mappers\BrandViewAssembler;
use App\Infrastructure\Catalog\Category\Mappers\CategoryViewAssembler;
use App\Infrastructure\Catalog\CustomFields\Repositories\EloquentCustomFieldRepository;
use App\Infrastructure\Catalog\Order\Mappers\OrderViewAssembler;
use App\Infrastructure\Catalog\Product\Mappers\ProductModelMapper;
use App\Infrastructure\Catalog\Product\Mappers\ProductVariationModelMapper;
use App\Infrastructure\Catalog\Product\Mappers\ProductViewAssembler;
use App\Infrastructure\Customer\Mappers\CustomerViewAssembler;
use App\Infrastructure\Shopwired\Clients\BasicProductUpdateClient;
use App\Infrastructure\Shopwired\Clients\BrandUpdateClient;
use App\Infrastructure\Shopwired\Clients\CategoryUpdateClient;
use App\Infrastructure\Shopwired\Clients\CustomerFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\ProductClient;
use App\Infrastructure\Shopwired\Clients\ProductFieldUpdateClient;
use App\Infrastructure\Shopwired\Clients\ProductUpdateClient;
use App\Infrastructure\Shopwired\Dispatchers\QueuedSaleReconciliationDispatcher;
use App\Infrastructure\Shopwired\Dispatchers\QueuedShopwiredSyncDispatcher;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\CustomFieldValueFactory;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use App\Infrastructure\Shopwired\Parsers\ShopwiredBrandWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredCategoryWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredCustomerWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredOrderWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredProductWebhookParser;
use App\Infrastructure\Shopwired\Repositories\EloquentBrandRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCategoryRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomerRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentFilterGroupRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentOrderRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentSaleSettingsRepository;
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
        $this->registerSingletonClients();
        $this->registerScopedReadClient();
        $this->registerScopedWriteClients();
        $this->registerUpdateClients();
    }

    private function registerSingletonClients(): void
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
        $this->app->singleton(WebhookClientInterface::class, static fn(): WebhookClientInterface => ShopwiredClientFactory::createWebhookClient());
    }

    private function registerScopedReadClient(): void
    {
        $this->app->scoped(
            ProductClientInterface::class,
            static fn(Application $app): ProductClientInterface => new ProductClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductDomainFactory::class),
            ),
        );
    }

    private function registerScopedWriteClients(): void
    {
        $this->app->scoped(
            ProductUpdateClientInterface::class,
            static fn(Application $app): ProductUpdateClientInterface => new ProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductClientInterface::class),
            ),
        );
        $this->app->scoped(
            BasicProductUpdateClientInterface::class,
            static fn(Application $app): BasicProductUpdateClientInterface => new BasicProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductRepositoryInterface::class),
            ),
        );
    }

    private function registerUpdateClients(): void
    {
        $this->app->singleton(ProductFieldUpdateClientInterface::class, static fn(): ProductFieldUpdateClientInterface => new ProductFieldUpdateClient(ShopwiredClientFactory::getTransport()));
        $this->app->singleton(CustomerFieldUpdateClientInterface::class, static fn(): CustomerFieldUpdateClientInterface => new CustomerFieldUpdateClient(ShopwiredClientFactory::getTransport()));

        $this->app->singleton(
            CategoryUpdateClientInterface::class,
            static fn(Application $app): CategoryUpdateClientInterface => new CategoryUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(CategoryClientInterface::class),
            ),
        );

        $this->app->singleton(
            BrandUpdateClientInterface::class,
            static fn(Application $app): BrandUpdateClientInterface => new BrandUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(BrandClientInterface::class),
            ),
        );
    }

    private function registerRepositories(): void
    {
        $this->app->singleton(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->singleton(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
        $this->app->singleton(CustomFieldRepositoryInterface::class, EloquentCustomFieldRepository::class);
        // Scoped: holds CustomFieldFactory with lazy-loaded registry (Octane isolation)
        $this->app->scoped(CategoryRepositoryInterface::class, EloquentCategoryRepository::class);
        $this->app->scoped(BrandRepositoryInterface::class, EloquentBrandRepository::class);
        $this->app->singleton(FilterGroupRepositoryInterface::class, EloquentFilterGroupRepository::class);
        $this->app->singleton(ProductIdentifierResolverInterface::class, ProductIdentifierResolver::class);
        $this->app->singleton(SaleSettingsRepositoryInterface::class, EloquentSaleSettingsRepository::class);

        // Scoped: fresh mapper per queue job (Octane isolation)
        $this->app->scoped(ProductRepositoryInterface::class, EloquentProductRepository::class);
    }

    private function registerFactories(): void
    {
        $this->app->scoped(ProductDomainFactory::class);
        $this->registerCustomFieldValueFactories();
        $this->registerCustomFieldFactories();
        $this->app->scoped(ProductFilterFactory::class);
        $this->app->scoped(ProductVariationModelMapper::class);
        $this->app->scoped(ProductModelMapper::class);
        $this->app->scoped(ProductViewAssembler::class);
        $this->app->scoped(CategoryViewAssembler::class);
        $this->app->scoped(BrandViewAssembler::class);
        $this->app->scoped(OrderViewAssembler::class);
        $this->app->scoped(CustomerViewAssembler::class);
    }

    private function registerCustomFieldValueFactories(): void
    {
        $this->app->when(UpdateProductCustomFieldsUseCase::class)
            ->needs(CustomFieldValueFactoryInterface::class)
            ->give(static fn(Application $app): CustomFieldValueFactory => new CustomFieldValueFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Product,
            ));
        $this->app->when(UpdateCategoryCustomFieldsUseCase::class)
            ->needs(CustomFieldValueFactoryInterface::class)
            ->give(static fn(Application $app): CustomFieldValueFactory => new CustomFieldValueFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Category,
            ));
        $this->app->when(UpdateBrandCustomFieldsUseCase::class)
            ->needs(CustomFieldValueFactoryInterface::class)
            ->give(static fn(Application $app): CustomFieldValueFactory => new CustomFieldValueFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Brand,
            ));
    }

    private function registerCustomFieldFactories(): void
    {
        $this->app->when([ProductModelMapper::class, ProductViewAssembler::class])
            ->needs(CustomFieldFactory::class)
            ->give(static fn(Application $app): CustomFieldFactory => new CustomFieldFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Product,
            ));
        $this->app->when(CategoryViewAssembler::class)
            ->needs(CustomFieldFactory::class)
            ->give(static fn(Application $app): CustomFieldFactory => new CustomFieldFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Category,
            ));
        $this->app->when(BrandViewAssembler::class)
            ->needs(CustomFieldFactory::class)
            ->give(static fn(Application $app): CustomFieldFactory => new CustomFieldFactory(
                $app->make(CustomFieldRepositoryInterface::class),
                CustomFieldItemType::Brand,
            ));
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
            AddProductToSaleUseCase::class,
            RemoveProductFromSaleUseCase::class,
        ])->needs('$saleCategoryId')
            ->give(static fn(): int => self::resolveNumericConfig(
                'shopwired.sale_category_id',
            ));
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
            ->give(static fn(): int => self::resolveNumericConfig(
                'shopwired.webhook_staleness_hours',
            ));
    }

    private static function resolveNumericConfig(string $key): int
    {
        $value = \config($key);

        if (! \is_numeric($value)) {
            throw new InvalidConfigurationException($key, "{$key} must be a numeric value");
        }

        return (int) $value;
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
            BrandRepositoryInterface::class,
            BrandUpdateClientInterface::class,
            BrandViewAssembler::class,
            BrandWebhookEventResolverInterface::class,
            BrandWebhookParserInterface::class,
            CategoryClientInterface::class,
            CategoryRepositoryInterface::class,
            CategoryUpdateClientInterface::class,
            CategoryViewAssembler::class,
            CategoryWebhookEventResolverInterface::class,
            CategoryWebhookParserInterface::class,
            ConnectivityClientInterface::class,
            CustomFieldClientInterface::class,
            CustomFieldRepositoryInterface::class,
            CustomerClientInterface::class,
            CustomerFieldUpdateClientInterface::class,
            CustomerRepositoryInterface::class,
            CustomerViewAssembler::class,
            CustomerWebhookEventResolverInterface::class,
            CustomerWebhookParserInterface::class,
            FilterGroupClientInterface::class,
            FilterGroupRepositoryInterface::class,
            OrderClientInterface::class,
            OrderRepositoryInterface::class,
            OrderViewAssembler::class,
            OrderWebhookEventResolverInterface::class,
            OrderWebhookParserInterface::class,
            ProductClientInterface::class,
            CustomFieldValueFactoryInterface::class,
            ProductFieldUpdateClientInterface::class,
            ProductDomainFactory::class,
            ProductFilterFactory::class,
            PriceUpdateClientInterface::class,
            ProductIdentifierResolverInterface::class,
            ProductModelMapper::class,
            ProductRepositoryInterface::class,
            ProductVariationModelMapper::class,
            ProductViewAssembler::class,
            ProductUpdateClientInterface::class,
            ProductWebhookEventResolverInterface::class,
            ProductWebhookParserInterface::class,
            SaleReconciliationDispatcherInterface::class,
            SaleSettingsRepositoryInterface::class,
            ShopwiredSyncDispatcherInterface::class,
            StockClientInterface::class,
            WebhookClientInterface::class,
            WebhookIdempotencyServiceInterface::class,
        ];
    }
}
