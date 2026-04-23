<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\MatchOneOfTheseNames;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotHaveDependencyOutsideNamespace;
use Arkitect\Expression\ForClasses\NotHaveNameMatching;
use Arkitect\Expression\ForClasses\NotResideInTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;
use Webmozart\Assert\Assert;

return static function (Config $config): void {
    $classSet = ClassSet::fromDir(__DIR__ . '/app');

    /*
     |--------------------------------------------------------------------------
     | PHPARKITECT: YOUR ARCHITECTURE TEACHER 🎓
     |--------------------------------------------------------------------------
     |
     | This configuration teaches clean architecture through real-time feedback.
     | Every rule is ENABLED NOW because:
     |
     | ✅ Catching mistakes on 9 files is easy
     | ❌ Discovering 50 violations on 30 files is painful
     |
     | LEARNING APPROACH:
     | When you violate a rule, you'll see:
     | - WHAT you did wrong (the error)
     | - WHY it matters (the because clause)
     | - HOW to fix it (examples in comments)
     |
     | This prevents your previous project's "mess" by making bad architecture
     | literally impossible to commit.
     |
     |--------------------------------------------------------------------------
     | YOUR ARCHITECTURE (FOR WEBHOOK/BACKGROUND JOB BACKEND)
     |--------------------------------------------------------------------------
     |
     |   Webhook/Queue → Controller/Command (Presentation)
     |                       ↓ delegates to
     |                   ProcessWebhookUseCase / Job (Application)
     |                       ↓ orchestrates
     |                   Order (Domain) + OrderRepositoryInterface (Domain)
     |                       ↑ implemented by
     |                   EloquentOrderRepository (Infrastructure)
     |
     | LAYERS:
     | - Domain: Business logic (Order, Product, calculations)
     | - Application: Use cases (SyncOrders, ProcessWebhook)
     | - Infrastructure: Implementations (EloquentOrderRepository, ApiClient, Jobs)
     | - Presentation: Entry points (Controllers, Console commands)
     |
     | RESOURCES:
     | - Clean Architecture: https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html
     | - Laravel DDD: https://laravel-beyond-crud.com/blog/laravel-domains
     */

    $rules = [];

    // Layer definitions
    $domain         = 'App\Domain';
    $application    = 'App\Application';
    $infrastructure = 'App\Infrastructure';
    $presentation   = 'App\Presentation';

    /*
     |--------------------------------------------------------------------------
     | ARCHITECTURAL RULES (ALL ENABLED)
     |--------------------------------------------------------------------------
     */

    // RULE 1: Domain Must Be Self-Contained
    //
    // WHY: Core business logic should work independently of framework/database/UI
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Domain\Order;
    //    use App\Application\TaxService;
    //    class Order {
    //        public function getTotal(TaxService $tax) { }  // Wrong direction!
    //    }
    //
    // CORRECT:
    // ✅ namespace App\Domain\Order;
    //    class Order {
    //        public function calculateTotal(TaxCalculator $calculator) { }  // Domain interface
    //    }
    //    namespace App\Application;
    //    class TaxService implements TaxCalculator { }  // Implements Domain
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($domain))
                   ->should(
                       new NotHaveDependencyOutsideNamespace(
                           $domain,
                           [
                               'DateTime',
                               'DateTimeImmutable',
                               'DateTimeZone',
                               'DateTimeInterface',
                               'DateInterval',
                               'DatePeriod',
                               'RuntimeException',
                               'InvalidArgumentException',
                               'LogicException',
                               'ValueError',
                               'Exception',
                               'Throwable',
                               'Override',
                               Assert::class,
                           ],
                       ),
                   )
                   ->because('the Domain layer should be self-contained and not depend on any other layer.');

    // RULE 2: Application Can Only Depend on Domain
    //
    // WHY: Use cases orchestrate domain logic, shouldn't know about database/HTTP
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Application;
    //    use App\Infrastructure\EloquentOrderRepository;
    //    class SyncOrders {
    //        public function __construct(EloquentOrderRepository $repo) { }  // Concrete class!
    //    }
    //
    // CORRECT:
    // ✅ namespace App\Application;
    //    use App\Domain\Order\OrderRepositoryInterface;
    //    class SyncOrders {
    //        public function __construct(OrderRepositoryInterface $orders) { }  // Interface!
    //    }
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($application))
                   ->should(
                       new NotHaveDependencyOutsideNamespace(
                           $application,
                           [
                               $domain,
                               'DateTime',
                               'DateTimeImmutable',
                               'DateTimeZone',
                               'DateTimeInterface',
                               'DateInterval',
                               'DatePeriod',
                               'Psr\Log\LoggerInterface',
                               'Illuminate\Contracts\Events',
                               'Psr\SimpleCache\CacheInterface',
                               'Closure',
                               'Generator',
                               'Spatie\LaravelData',
                               Assert::class,
                               'RuntimeException',
                               'LogicException',
                               'Exception',
                               'Throwable',
                               'Psr\SimpleCache\CacheException',
                               'Override',
                           ],
                       ),
                   )
                   ->because('the Application layer can only depend on the Domain layer.');

    // RULE 3: Infrastructure Implements Domain/Application Interfaces
    //
    // WHY: This layer provides concrete implementations for framework-specific code
    //
    // EXAMPLE:
    // ✅ namespace App\Infrastructure\Database;
    //    use App\Domain\Order\OrderRepositoryInterface;
    //    class EloquentOrderRepository implements OrderRepositoryInterface {
    //        public function findUnsynced() {
    //            return Order::where('synced', false)->get();  // Eloquent OK here
    //        }
    //    }
    //
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($infrastructure))
                   ->should(
                       new NotHaveDependencyOutsideNamespace(
                           $infrastructure,
                           [
                               $application,
                               $domain,
                               'Illuminate',
                               'DateTime',
                               'DateTimeImmutable',
                               'DateTimeZone',
                               'DateTimeInterface',
                               'DateInterval',
                               'DatePeriod',
                               Assert::class,
                               'Exception',
                               'RuntimeException',
                               'InvalidArgumentException',
                               'LogicException',
                               'ValueError',
                               'Throwable',
                               'JsonException',
                               'Spatie\LaravelData',
                               'Carbon\*',
                               'Closure',
                               'Generator',
                               'Google*',
                               'GuzzleHttp*',
                               'Microsoft*',
                               'HelpScout*',
                               'TheIconic*',
                               'libphonenumber*',
                               'SoapClient',
                               'SoapFault',
                               'SoapVar',
                               'ZipArchive',
                               'Psr',
                               'League\Flysystem',
                               'XMLReader',
                               'SimpleXMLElement',
                               'PDOException',
                               'Override',
                               'Laravel\Horizon',
                               'Sentry*',
                               'Monolog*',
                               'Symfony\Component\HttpFoundation',
                               'Random\RandomException',
                               'Reflection*',
                               'BackedEnum',
                           ],
                       ),
                   )
                   ->because('the Infrastructure layer implements interfaces from Application and Domain layers.');

    // RULE 4: Presentation Uses Application Services
    //
    // WHY: Controllers/Console are entry points that delegate to Application layer
    //
    // EXAMPLE:
    // ✅ namespace App\Presentation\Http\Controllers;
    //    use App\Application\Webhooks\ProcessWebhook;
    //    class WebhookController {
    //        public function handle(ProcessWebhook $useCase) {
    //            $useCase->execute(request()->all());  // Delegation
    //            return response()->json(['status' => 'ok']);
    //        }
    //    }
    //
    // EXCEPTION: Commands\Dev\ may access Infrastructure for dev/debug utilities
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($presentation))
                   ->andThat(new NotResideInTheseNamespaces('App\Presentation\Console\Commands\Dev'))
                   ->should(
                       new NotHaveDependencyOutsideNamespace(
                           $presentation,
                           [
                               $application,
                               $domain,
                               'Illuminate',
                               'Carbon',
                               'DateTime',
                               'DateTimeImmutable',
                               'DateTimeZone',
                               'DateTimeInterface',
                               'DateInterval',
                               'DatePeriod',
                               'RuntimeException',
                               'InvalidArgumentException',
                               'LogicException',
                               'Exception',
                               'Throwable',
                               'Override',
                               'Closure',
                               'stdClass',
                               'Symfony\Component\HttpFoundation',
                               'Symfony\Component\HttpKernel',
                               'Firebase\JWT',
                               'Spatie\LaravelData',
                           ],
                       ),
                   )
                   ->because(
                       'the Presentation layer uses Application services and may handle Domain objects they return.',
                   );

    // RULE 4b: Repositories must not depend on Application DTOs
    //
    // WHY: Repositories are persistence abstractions that speak in Domain objects.
    // Application DTOs are presentation-facing transfer formats (often carrying
    // framework concerns like Spatie LaravelData, validation rules, etc.). A
    // repository importing an Application DTO couples persistence to presentation
    // concerns and makes Domain objects harder to reuse independently.
    //
    // Rule 3 allows Infrastructure to depend on the whole Application namespace
    // because repositories implement Application contracts. This rule narrows
    // that allowance for the Repositories/ subdirectory specifically.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Infrastructure\Catalog\Repositories;
    //    use App\Application\Catalog\DTOs\ProductResponseDTO;   // Wrong!
    //    class EloquentProductRepository {
    //        public function find(): ProductResponseDTO { }
    //    }
    //
    // CORRECT:
    // ✅ namespace App\Infrastructure\Catalog\Repositories;
    //    use App\Domain\Catalog\Product\Product;   // Domain object
    //    class EloquentProductRepository {
    //        public function find(): Product { }
    //    }
    //
    // NOTE on pattern syntax: PHPArkitect wildcard patterns go through fnmatch, which
    // requires explicit trailing `*` to match descendants (no implicit prefix match when
    // the pattern already contains wildcards). Two patterns are needed to cover both the
    // root DTOs namespace and nested ones.
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\*\Repositories'))
                   ->should(new NotDependsOnTheseNamespaces([
                       'App\Application\DTOs\*',
                       'App\Application\*\DTOs\*',
                   ]))
                   ->because('Repositories are Domain persistence abstractions and must not depend on Application DTOs.');

    // NOTE: Additional architectural constraints (like "Controllers must not depend on
    // Infrastructure" or "Domain must not use Facades") are already enforced by Rules 1-4.
    // Rule 1 ensures Domain is self-contained (automatically prevents HTTP/Queue/Facades).
    // Rule 4 ensures Presentation only depends on Application/Domain (prevents direct Infrastructure access).

    /*
     |--------------------------------------------------------------------------
     | NAMING CONVENTIONS (ALL ENABLED - ENFORCED WHEN YOU CREATE CLASSES)
     |--------------------------------------------------------------------------
     |
     | These ensure your classes are named correctly FROM DAY ONE.
     | When you create your first OrderRepository, it MUST be named correctly.
     | When you create your first SyncOrdersUseCase, it MUST be named correctly.
     |
     | This prevents: OrderRepo, RepositoryForOrders, HandleOrders, etc.
     */

    // Controllers must end with "Controller"
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces('App\Presentation\Http\Controllers'))
                   ->should(new HaveNameMatching('*Controller'))
                   ->because('Controllers should have a "Controller" suffix for clarity.');

    // RULE 9: Presentation Layer Naming Convention (Whitelist)
    //
    // WHY: Entry points should have clear, consistent naming. Controllers handle
    // HTTP requests, Commands handle CLI, Jobs handle queue messages.
    //
    // CORRECT:
    // ✅ namespace App\Presentation\Http\Controllers;
    //    class WebhookController { }  // Handles HTTP webhooks
    //
    // ✅ namespace App\Presentation\Console\Commands;
    //    class SyncDataCommand { }  // CLI command
    //
    // VIOLATION:
    // ❌ namespace App\Presentation\Http;
    //    class WebhookHandler { }  // Missing *Controller/*Command suffix
    //
    // NOTE: Jobs live in Infrastructure layer (App\Infrastructure\Jobs\*), not Presentation.
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($presentation))
                   ->andThat(new NotHaveNameMatching('*Exception'))
                   ->should(new MatchOneOfTheseNames(['*Controller', '*Command', '*Middleware', '*Parser', '*Resource', '*Request', '*Trait', '*Factory', '*DTO', '*Mapper', '*Notification', '*Enum']))
                   ->because(
                       'Presentation layer classes should be clearly identifiable as controllers, commands, middleware, parsers, resources, form requests, traits, factories, DTOs, mappers, notifications, or enums.',
                   );

    // Application services must end with "UseCase", "Service", "Transformer", "Formatter", or "Interface"
    //
    // EXCLUSIONS:
    // - CacheTimesTrait: Trait holding shared cache duration constants
    // - GracefulCache: Utility class for graceful cache operations
    // - Enums subdirectories: Type-safe enums don't need behavioral naming
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($application))
                   ->andThat(new NotHaveNameMatching('CacheTimesTrait'))
                   ->andThat(new NotHaveNameMatching('GracefulCache'))
                   ->andThat(new NotResideInTheseNamespaces(
                       'App\Application\HelpScout\Config',
                       'App\Application\Enums',
                       'App\Application\HelpScout\Queries\Conversation\Enums',
                       'App\Application\Inventory\Enums',
                       'App\Application\Linnworks\Enums',
                       'App\Application\Shopwired\Enums',
                   ))
                   ->should(
                       new MatchOneOfTheseNames(['*UseCase', '*Service', '*Transformer', '*Formatter', '*Sorter', '*Resolver', '*Interface', '*DTO', '*Exception', '*Result', '*Params', '*Command', '*Validator']),
                   )
                   ->because(
                       'Application layer classes should be clearly identifiable as use cases, services, transformers, formatters, sorters, resolvers, interfaces, commands, validators, or parameter objects.',
                   );

    // RULE 5: No interfaces in Infrastructure
    //
    // WHY: Infrastructure implements contracts, doesn't define them.
    // Public interfaces belong in Domain or Application layers.
    //
    // EXCEPTION: Internal Infrastructure contracts (marked @internal) for:
    // - DomainConvertibleInterface: Marks DTOs that can convert to Domain objects
    // - DomainConvertibleChildInterface: Marks child DTOs needing parent ID to convert
    // - PaginatableQueryParamsInterface: Marks query params supporting pagination
    // - EloquentDomainMappableInterface: Marks Eloquent models with domain mapping
    // - LinnworksTransportInterface: Internal transport abstraction for decorator pattern
    // - LinnworksQueryInterface: Internal query object abstraction for SQL queries
    // - ShopwiredTransportInterface: Internal transport abstraction for decorator pattern
    // - MixpanelTransportInterface: Internal transport abstraction for decorator pattern
    // These are internal implementation patterns, not cross-layer contracts.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Infrastructure\Database;
    //    interface OrderRepositoryInterface { }  // Wrong layer!
    //    class EloquentOrderRepository implements OrderRepositoryInterface { }
    //
    // CORRECT:
    // ✅ namespace App\Domain\Contracts;
    //    interface OrderRepositoryInterface { }  // Domain defines contract
    //    namespace App\Infrastructure\Database;
    //    class EloquentOrderRepository implements OrderRepositoryInterface { }
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Interface'))
                   ->andThat(new NotHaveNameMatching('DomainConvertibleInterface'))
                   ->andThat(new NotHaveNameMatching('DomainConvertibleChildInterface'))
                   ->andThat(new NotHaveNameMatching('PaginatableQueryParamsInterface'))
                   ->andThat(new NotHaveNameMatching('EloquentDomainMappableInterface'))
                   ->andThat(new NotHaveNameMatching('LinnworksTransportInterface'))
                   ->andThat(new NotHaveNameMatching('LinnworksQueryInterface'))
                   ->andThat(new NotHaveNameMatching('ShopwiredTransportInterface'))
                   ->andThat(new NotHaveNameMatching('MixpanelTransportInterface'))
                   ->should(new NotResideInTheseNamespaces($infrastructure))
                   ->because(
                       'Public interfaces belong in Domain or Application layers. Infrastructure implements contracts defined by higher layers.',
                   );

    // RULE 6: Interfaces must be in Contracts subdirectories
    //
    // WHY: Enforces consistent organization and makes contracts easy to discover.
    //
    // NOTE: Currently only App\Application\Contracts exists. App\Domain\Contracts
    // is ASPIRATIONAL - reserved for future repository interfaces when we add
    // database persistence (e.g., OrderRepositoryInterface, ProductRepositoryInterface).
    //
    // EXCEPTION: Internal Infrastructure contracts are allowed in Infrastructure\Contracts
    // and Infrastructure\*\Contracts for internal implementation patterns.
    //
    // CURRENT:
    // ✅ namespace App\Application\Contracts;
    //    interface MixpanelClientInterface { }
    //
    // FUTURE (when adding database layer):
    // ✅ namespace App\Domain\Contracts;
    //    interface OrderRepositoryInterface { }
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Interface'))
                   ->should(
                       new ResideInOneOfTheseNamespaces(
                           'App\Domain\Contracts',
                           'App\Domain\*\Contracts',
                           'App\Application\Contracts',
                           'App\Infrastructure\Contracts',
                           'App\Infrastructure\*\Contracts',
                       ),
                   )
                   ->because('Interfaces must be organized in Contracts subdirectories for easy discovery.');

    // RULE 7: Contracts directories only contain interfaces
    //
    // WHY: Prevents mixing interfaces with implementations. Contracts directories
    // are for definitions only, implementations live elsewhere.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Application\Contracts;
    //    class OrderService { }  // Wrong! Should be in UseCases/Services
    //
    // CORRECT:
    // ✅ namespace App\Application\Contracts;
    //    interface OrderServiceInterface { }
    //    namespace App\Application\Services;
    //    class OrderService implements OrderServiceInterface { }
    //
    $rules[] = Rule::allClasses()
                   ->that(
                       new ResideInOneOfTheseNamespaces(
                           'App\Domain\Contracts',
                           'App\Domain\*\Contracts',
                           'App\Application\Contracts',
                       ),
                   )
                   ->should(new HaveNameMatching('*Interface'))
                   ->because('Contracts directories should only contain interfaces, not implementations.');

    // RULE 8: No DTOs in Domain Layer
    //
    // WHY: Domain contains business concepts (ValueObjects, Entities), not
    // transfer formats (DTOs). DTOs belong in Application/Infrastructure
    // for data transfer between layers or external systems.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Domain\Order;
    //    class OrderDTO { }  // DTOs don't belong in Domain!
    //
    // CORRECT:
    // ✅ namespace App\Domain\Order\ValueObjects;
    //    class Order { }  // Pure business concept
    //
    //    namespace App\Infrastructure\Api\DTOs;
    //    class OrderDTO { }  // Transfer format for external API
    //
    //    namespace App\Application\DTOs;
    //    class OrderForApiDTO { }  // Transfer format for response
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*DTO'))
                   ->should(new NotResideInTheseNamespaces($domain))
                   ->because(
                       'DTOs are data transfer formats for Infrastructure/Application layers. Domain should only contain ValueObjects representing pure business concepts.',
                   );

    // RULE 8b: Classes in DTOs/ directories must have *DTO suffix
    //
    // WHY: Enforces consistent naming for Data Transfer Objects.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Infrastructure\Mixpanel\DTOs;
    //    class AdSpendEvent { }  // Missing DTO suffix
    //
    // CORRECT:
    // ✅ namespace App\Infrastructure\Mixpanel\DTOs;
    //    class AdSpendEventDTO { }  // Clear intent
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces('App\*\DTOs'))
                   ->should(new HaveNameMatching('*DTO'))
                   ->because('Classes in DTOs/ directories must have "DTO" suffix for clarity.');

    // RULE 8c: Classes in Responses/ directories must have *Response suffix
    //
    // WHY: Distinguishes API response parsing classes from other types.
    // Response classes parse external API JSON into typed objects with toDomain() methods.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Infrastructure\HelpScout\Responses;
    //    class Conversation { }  // Missing Response suffix
    //
    // CORRECT:
    // ✅ namespace App\Infrastructure\HelpScout\Responses;
    //    class ConversationResponse { }  // Clear intent
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\*\Responses'))
                   ->should(new HaveNameMatching('*Response'))
                   ->because(
                       'API response classes should have "Response" suffix to distinguish them from Domain objects.',
                   );

    // RULE 9: Organization Rule - All Domain classes must be in subdirectories
    //
    // WHY: Clear structure makes concepts discoverable and maintainable.
    // Every Domain class belongs in a specific folder (ValueObjects, Entities, etc).
    //
    // NOTE: CURRENT vs ASPIRATIONAL directories:
    // ✅ CURRENTLY EXISTS:
    //    - App\Domain\AdSpend\ValueObjects (nested: Campaign, CampaignMetrics)
    //    - App\Domain\Exceptions (root level exceptions)
    //
    // 🔮 ASPIRATIONAL (for future growth):
    //    - App\Domain\ValueObjects (root level shared value objects)
    //    - App\Domain\Entities (stateful business objects with identity)
    //    - App\Domain\*\Entities (concept-specific entities)
    //    - App\Domain\Contracts (repository interfaces when adding persistence)
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Domain;
    //    class Order { }  // Loose class at root level
    //
    // CURRENT CORRECT:
    // ✅ namespace App\Domain\AdSpend\ValueObjects;
    //    class Campaign { }  // Organized in subdirectory
    //
    // ✅ namespace App\Domain\Exceptions;
    //    class UnexpectedApiResultException { }  // Root level exception
    //
    // FUTURE CORRECT (when adding persistence):
    // ✅ namespace App\Domain\Order\Entities;
    //    class OrderItem { }  // Stateful object with identity
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($domain))
                   ->should(new ResideInOneOfTheseNamespaces(
                       'App\Domain\ValueObjects',
                       'App\Domain\*\ValueObjects',
                       'App\Domain\Entities',
                       'App\Domain\*\Entities',
                       'App\Domain\Enums',
                       'App\Domain\*\Enums',
                       'App\Domain\Exceptions',
                       'App\Domain\Exceptions\*',      // Allow subdirectories (Api/, Data/, Infrastructure/)
                       'App\Domain\*\Exceptions',
                       'App\Domain\Contracts',
                       'App\Domain\*\Contracts',
                       'App\Domain\*\Concerns',
                       'App\Domain\*\Commands',
                       'App\Domain\*\Resolvers',
                       'App\Domain\*\Transformers',
                       'App\Domain\*\Events',
                       'App\Domain\*\Validators',
                   ))
                   ->because(
                       'Domain classes must be organized into Value Objects, Entities, Enums, Exceptions, '
                       . 'Contracts, Concerns, Commands, Resolvers, Transformers, Events, or Validators subdirectories for discoverability and maintainability.',
                   );

    // RULE V1: Validator Placement - *Validator classes in Domain must be in Validators/ namespace
    //
    // WHY: Validators co-locate with their domain concept for discoverability.
    // Without this, validators can be dropped in ValueObjects/, Entities/, etc.
    //
    // CORRECT:
    // ✅ namespace App\Domain\Catalog\Product\Validators;
    //    class SkuBelongsToProductValidator { }
    //
    // VIOLATION:
    // ❌ namespace App\Domain\Catalog\Product\ValueObjects;
    //    class SkuBelongsToProductValidator { }  // Wrong directory!
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Validator'))
                   ->andThat(new ResideInOneOfTheseNamespaces($domain))
                   ->should(new ResideInOneOfTheseNamespaces('App\Domain\*\Validators'))
                   ->because('Domain validators must reside in Validators/ subdirectories alongside their domain concept.');

    // RULE V2: Validators/ directories only contain Validators and Results
    //
    // WHY: Keeps Validators/ directories focused. Prevents enums, helpers, etc. from creeping in.
    //
    // CORRECT:
    // ✅ SkuBelongsToProductValidator, SkuBelongsToProductResult
    //
    // VIOLATION:
    // ❌ namespace App\Domain\Catalog\Product\Validators;
    //    enum ValidationStatus { }  // Wrong! Enums go in Enums/
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces('App\Domain\*\Validators'))
                   ->should(new MatchOneOfTheseNames(['*Validator', '*Result']))
                   ->because('Validators/ directories may only contain *Validator and *Result classes.');

    // RULE 10: All Exceptions must end with *Exception suffix
    //
    // WHY: Immediate clarity that a class is throwable/catchable.
    //
    // VIOLATION EXAMPLE:
    // ❌ namespace App\Domain\Exceptions;
    //    class InsufficientStock { }  // Missing Exception suffix
    //
    // CORRECT:
    // ✅ namespace App\Domain\Exceptions;
    //    class InsufficientStockException { }  // Clear intent
    //
    // ✅ namespace App\Domain\AdSpend\Exceptions;
    //    class InsufficientStockException { }  // Or nested under concept
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Exception'))
                   ->should(new ResideInOneOfTheseNamespaces(
                       'App\Domain\Exceptions',
                       'App\Domain\Exceptions\*',         // Allow subdirectories (Api/, Data/, Infrastructure/)
                       'App\Domain\*\Exceptions',
                       'App\Application\Exceptions',
                       'App\Application\*\Exceptions',
                       'App\Infrastructure\Exceptions',
                       'App\Infrastructure\*\Exceptions',
                       'App\Presentation\*\Exceptions',
                   ))
                   ->because(
                       'All exception classes must reside in Exceptions/ subdirectories '
                       . 'and end with Exception suffix for clarity and consistency.',
                   );

    // RULE 11: Events must be in Events/ directories within Domain layer
    //
    // WHY: Events represent business concepts (things that happened).
    // They belong in Domain and should be organized in Events/ subdirectories.
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Event'))
                   ->should(new ResideInOneOfTheseNamespaces(
                       'App\Domain\Events',
                       'App\Domain\*\Events',
                   ))
                   ->because('Events represent business concepts and must reside in Domain layer Events/ subdirectories.');

    // RULE 12: Listeners must be in Listeners/ directories within Infrastructure layer
    //
    // WHY: Listeners handle side effects (notifications, external services).
    // They belong in Infrastructure and should be organized in Listeners/ subdirectories.
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Listener'))
                   ->should(new ResideInOneOfTheseNamespaces(
                       'App\Infrastructure\Listeners',
                       'App\Infrastructure\*\Listeners',
                   ))
                   ->because('Listeners handle side effects and must reside in Infrastructure layer Listeners/ subdirectories.');

    // RULE 13: Validators must be in Validators/ directories and end with *Validator suffix
    //
    // WHY: Co-located validators are discoverable alongside the thing they validate.
    // Naming convention gives PHPArkitect a reliable signal for enforcement.
    // Application-layer validators are allowed when they depend on Application contracts
    // (e.g., CustomFieldSubmissionValidator depends on CustomFieldValueFactoryInterface).
    //
    $rules[] = Rule::allClasses()
                   ->that(new HaveNameMatching('*Validator'))
                   ->should(new ResideInOneOfTheseNamespaces(
                       'App\Domain\*\Validators',
                       'App\Application\*\Validators',
                   ))
                   ->because('Validators must be co-located with their concept (Domain or Application Validators/ subdirectories), not in a top-level catch-all.');

    $config->add($classSet, ...$rules);
    /*
     |--------------------------------------------------------------------------
     | TROUBLESHOOTING GUIDE
     |--------------------------------------------------------------------------
     |
     | ERROR: "Domain depends on Application"
     | FIX: Invert the dependency. Domain defines interface, Application implements.
     |
     | ERROR: "Controller depends on Infrastructure"
     | FIX: Controller should call Application service, not repository directly.
     |
     | ERROR: "Domain depends on Illuminate\Http"
     | FIX: HTTP handling belongs in Presentation. Pass primitives to Domain.
     |
     | ERROR: "Application depends on Infrastructure"
     | FIX: Application depends on Domain interfaces, not Infrastructure classes.
     |
     | ERROR: "Class OrderRepo doesn't match *Repository"
     | FIX: Rename to OrderRepository (full word, not abbreviation).
     |
     | ERROR: "Class HandleOrders doesn't match *UseCase|*Service"
     | FIX: Rename to ProcessOrdersUseCase or OrderService (explicit suffix).
     |
     |--------------------------------------------------------------------------
     | YOUR LEARNING PATH
     |--------------------------------------------------------------------------
     |
     | 1. Create your first feature (e.g., Process webhook)
     | 2. PHPArkitect will guide you: "Controller depends on Infrastructure"
     | 3. Fix it: Move logic to Application layer
     | 4. Learn the pattern once, never make that mistake again
     | 5. Repeat for each feature
     |
     | Each violation is a teaching moment happening in REAL-TIME on a small
     | codebase (9 files). This is infinitely easier than discovering 50
     | violations later when you have 30 files.
     |
     | This is how you avoid repeating your previous project's "mess"!
     */
};
