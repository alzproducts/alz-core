<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
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
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Infrastructure\Shopwired\Clients\BasicProductUpdateClient;
use App\Infrastructure\Shopwired\Clients\ProductClient;
use App\Infrastructure\Shopwired\Clients\ProductUpdateClient;
use App\Infrastructure\Shopwired\Factories\ProductCustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use App\Infrastructure\Shopwired\Mappers\ProductModelMapper;
use App\Infrastructure\Shopwired\Parsers\ShopwiredCustomerWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredOrderWebhookParser;
use App\Infrastructure\Shopwired\Parsers\ShopwiredProductWebhookParser;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomerRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomFieldRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentFilterGroupRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentOrderRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredCustomerWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredOrderWebhookEventResolver;
use App\Infrastructure\Shopwired\Resolvers\ShopwiredProductWebhookEventResolver;
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
    /**
     * Register ShopWired API clients.
     *
     * Delegates to ShopwiredClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - Dependency wiring (Config → Transport → Client)
     * - Transport singleton management (shared across all clients)
     */
    #[Override]
    public function register(): void
    {
        // Connectivity client - for API health checks
        $this->app->singleton(
            ConnectivityClientInterface::class,
            static fn(): ConnectivityClientInterface => ShopwiredClientFactory::createConnectivityClient(),
        );

        // Category client - for category operations
        $this->app->singleton(
            CategoryClientInterface::class,
            static fn(): CategoryClientInterface => ShopwiredClientFactory::createCategoryClient(),
        );

        // Custom field client - for custom field definitions
        $this->app->singleton(
            CustomFieldClientInterface::class,
            static fn(): CustomFieldClientInterface => ShopwiredClientFactory::createCustomFieldClient(),
        );

        // Filter group client - for filter group definitions
        $this->app->singleton(
            FilterGroupClientInterface::class,
            static fn(): FilterGroupClientInterface => ShopwiredClientFactory::createFilterGroupClient(),
        );

        // Customer client - for customer operations
        $this->app->singleton(
            CustomerClientInterface::class,
            static fn(): CustomerClientInterface => ShopwiredClientFactory::createCustomerClient(),
        );

        // Order client - for order operations
        $this->app->singleton(
            OrderClientInterface::class,
            static fn(): OrderClientInterface => ShopwiredClientFactory::createOrderClient(),
        );

        // Stock client - for stock quantity updates
        $this->app->singleton(
            StockClientInterface::class,
            static fn(): StockClientInterface => ShopwiredClientFactory::createStockClient(),
        );

        // Product domain factory - scoped to prevent stale state in Octane
        // Used by ProductClient (write path) for DTO→Domain transformation
        $this->app->scoped(ProductDomainFactory::class);

        // Product client - scoped because it depends on scoped ProductDomainFactory
        // Uses closure to wire transport from factory + scoped factory from container
        $this->app->scoped(
            ProductClientInterface::class,
            static fn(Application $app): ProductClientInterface => new ProductClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductDomainFactory::class),
            ),
        );

        // Order repository - for local database persistence
        $this->app->singleton(
            OrderRepositoryInterface::class,
            EloquentOrderRepository::class,
        );

        // Customer repository - for local database persistence
        $this->app->singleton(
            CustomerRepositoryInterface::class,
            EloquentCustomerRepository::class,
        );

        // Custom field repository - for local database persistence
        $this->app->singleton(
            CustomFieldRepositoryInterface::class,
            EloquentCustomFieldRepository::class,
        );

        // Filter group repository - for local database persistence
        $this->app->singleton(
            FilterGroupRepositoryInterface::class,
            EloquentFilterGroupRepository::class,
        );

        // Product custom field factory - scoped to prevent stale registry in Octane
        $this->app->scoped(ProductCustomFieldFactory::class);

        // Product filter factory - scoped to prevent stale registry in Octane
        $this->app->scoped(ProductFilterFactory::class);

        // Product model mapper - scoped as it depends on ProductCustomFieldFactory and ProductFilterFactory
        $this->app->scoped(ProductModelMapper::class);

        // Product repository - scoped for fresh mapper per queue job
        $this->app->scoped(
            ProductRepositoryInterface::class,
            EloquentProductRepository::class,
        );

        // Product identifier resolver - resolves SKU/ID to ShopWired product ID
        $this->app->singleton(
            ProductIdentifierResolverInterface::class,
            ProductIdentifierResolver::class,
        );

        // Webhook event resolvers - map topic strings to domain intents (stateless)
        $this->app->singleton(OrderWebhookEventResolverInterface::class, ShopwiredOrderWebhookEventResolver::class);
        $this->app->singleton(ProductWebhookEventResolverInterface::class, ShopwiredProductWebhookEventResolver::class);
        $this->app->singleton(CustomerWebhookEventResolverInterface::class, ShopwiredCustomerWebhookEventResolver::class);

        // Webhook parsers - parse webhook payloads to domain objects
        // Order and customer parsers are stateless → singleton
        $this->app->singleton(OrderWebhookParserInterface::class, ShopwiredOrderWebhookParser::class);
        $this->app->singleton(CustomerWebhookParserInterface::class, ShopwiredCustomerWebhookParser::class);
        // Product parser depends on scoped ProductDomainFactory → must also be scoped
        $this->app->scoped(ProductWebhookParserInterface::class, ShopwiredProductWebhookParser::class);

        // Product update client - scoped because it depends on scoped ProductClientInterface
        $this->app->scoped(
            ProductUpdateClientInterface::class,
            static fn(Application $app): ProductUpdateClientInterface => new ProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductClientInterface::class),
            ),
        );

        // Basic product update client - scoped because it depends on scoped ProductRepositoryInterface
        $this->app->scoped(
            BasicProductUpdateClientInterface::class,
            static fn(Application $app): BasicProductUpdateClientInterface => new BasicProductUpdateClient(
                ShopwiredClientFactory::getTransport(),
                $app->make(ProductRepositoryInterface::class),
            ),
        );
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
            CategoryClientInterface::class,
            ConnectivityClientInterface::class,
            CustomFieldClientInterface::class,
            CustomFieldRepositoryInterface::class,
            CustomerClientInterface::class,
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
            ProductDomainFactory::class,
            ProductFilterFactory::class,
            ProductIdentifierResolverInterface::class,
            ProductModelMapper::class,
            ProductRepositoryInterface::class,
            ProductUpdateClientInterface::class,
            ProductWebhookEventResolverInterface::class,
            ProductWebhookParserInterface::class,
            StockClientInterface::class,
        ];
    }
}
