<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\MatchOneOfTheseNames;
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
     |   Webhook/Queue → Controller/Job (Presentation)
     |                       ↓ delegates to
     |                   ProcessWebhookUseCase (Application)
     |                       ↓ orchestrates
     |                   Order (Domain) + OrderRepositoryInterface (Domain)
     |                       ↑ implemented by
     |                   EloquentOrderRepository (Infrastructure)
     |
     | LAYERS:
     | - Domain: Business logic (Order, Product, calculations)
     | - Application: Use cases (SyncOrders, ProcessWebhook)
     | - Infrastructure: Implementations (EloquentOrderRepository, ApiClient)
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
                               'Throwable',
                               'Exception',
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
                               'Psr\SimpleCache\CacheInterface',
                               'Closure',
                               'Spatie\LaravelData',
                               'RuntimeException',
                               'InvalidArgumentException',
                               'LogicException',
                               'Throwable',
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
                               'BadMethodCallException',
                               'Throwable',
                               'JsonException',
                               'Spatie\LaravelData',
                               'Closure',
                               'Google*',
                               'Microsoft*',
                               'SoapClient',
                               'SoapFault',
                               'Psr',
                               'League\Flysystem',
                               'XMLReader',
                               'SimpleXMLElement',
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
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($presentation))
                   ->should(
                       new NotHaveDependencyOutsideNamespace(
                           $presentation,
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
                               'Throwable',
                               'RuntimeException',
                               'InvalidArgumentException',
                               'LogicException',
                               'Exception',
                               'Closure',
                               'Symfony\Component\HttpFoundation',
                               'Symfony\Component\HttpKernel',
                               'Firebase\JWT',
                           ],
                       ),
                   )
                   ->because(
                       'the Presentation layer uses Application services and may handle Domain objects they return.',
                   );

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
    // ✅ namespace App\Presentation\Jobs;
    //    class ProcessWebhookJob implements ShouldQueue {  // Queue job
    //        public function handle(ProcessWebhookUseCase $useCase) {
    //            $useCase->execute($this->data);  // Delegates to Application
    //        }
    //    }
    //
    // VIOLATION:
    // ❌ namespace App\Presentation\Http;
    //    class WebhookHandler { }  // Missing *Controller/*Command/*Job suffix
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($presentation))
                   ->should(new MatchOneOfTheseNames(['*Controller', '*Command', '*Job', '*Middleware']))
                   ->because(
                       'Presentation layer classes should be clearly identifiable as controllers, commands, jobs, or middleware.',
                   );

    // Application services must end with "UseCase", "Service", "Transformer", "Formatter", or "Interface"
    //
    // EXCLUSION: CacheTimesTrait is a trait holding shared cache duration constants.
    // Traits with constants don't fit behavioral naming (*Service, *UseCase) and
    // Clean Architecture doesn't mandate naming for utility code.
    //
    $rules[] = Rule::allClasses()
                   ->that(new ResideInOneOfTheseNamespaces($application))
                   ->andThat(new NotHaveNameMatching('CacheTimesTrait'))
                   ->andThat(new NotHaveNameMatching('GracefulCache'))
                   ->should(
                       new MatchOneOfTheseNames(['*UseCase', '*Service', '*Transformer', '*Formatter', '*Interface', '*DTO', '*Exception', '*Result']),
                   )
                   ->because(
                       'Application layer classes should be clearly identifiable as use cases, services, transformers, formatters, or interfaces.',
                   );

    // RULE 5: No interfaces in Infrastructure
    //
    // WHY: Infrastructure implements contracts, doesn't define them.
    // Public interfaces belong in Domain or Application layers.
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
                           'App\Application\Contracts',
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
                       'App\Domain\*\Exceptions',
                       'App\Domain\Contracts',
                   ))
                   ->because(
                       'Domain classes must be organized into Value Objects, Entities, Enums, Exceptions, '
                       . 'or Contracts subdirectories for discoverability and maintainability.',
                   );

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
                       'App\Domain\*\Exceptions',
                       'App\Application\Exceptions',
                       'App\Application\*\Exceptions',
                       'App\Infrastructure\Exceptions',
                       'App\Infrastructure\*\Exceptions',
                   ))
                   ->because(
                       'All exception classes must reside in Exceptions/ subdirectories '
                       . 'and end with Exception suffix for clarity and consistency.',
                   );

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
