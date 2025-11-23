<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\MatchOneOfTheseNames;
use Arkitect\Expression\ForClasses\NotHaveDependencyOutsideNamespace;
use Arkitect\Expression\ForClasses\NotResideInTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

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
    $domain = 'App\Domain';
    $application = 'App\Application';
    $infrastructure = 'App\Infrastructure';
    $presentation = 'App\Presentation';

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
        ->should(new NotHaveDependencyOutsideNamespace($domain, ['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateTimeInterface', 'DateInterval', 'DatePeriod', 'Closure', 'RuntimeException', 'InvalidArgumentException', 'LogicException', 'Throwable', 'JsonException', 'Webmozart\Assert\Assert']))
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
        ->should(new NotHaveDependencyOutsideNamespace($application, [$domain, 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateTimeInterface', 'DateInterval', 'DatePeriod', 'Spatie\LaravelData', 'RuntimeException', 'InvalidArgumentException', 'LogicException', 'Throwable', 'Illuminate\Support\Facades\Log']))
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
        ->should(new NotHaveDependencyOutsideNamespace($infrastructure, [$application, $domain, 'Illuminate', 'Illuminate\Support\Collection', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateTimeInterface', 'DateInterval', 'DatePeriod', 'Webmozart\Assert\Assert', 'RuntimeException', 'InvalidArgumentException', 'LogicException', 'Throwable', 'JsonException', 'Spatie\LaravelData', 'Closure', 'Google*']))
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
        ->should(new NotHaveDependencyOutsideNamespace($presentation, [$application, $domain, 'Illuminate', 'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateTimeInterface', 'DateInterval', 'DatePeriod', 'Throwable']))
        ->because('the Presentation layer uses Application services and may handle Domain objects they return.');

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

    // Application services must end with "UseCase", "Service", "Transformer", "Formatter", or "Interface"
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces($application))
        ->should(new MatchOneOfTheseNames(['*UseCase', '*Service', '*Transformer', '*Formatter', '*Interface']))
        ->because('Application layer classes should be clearly identifiable as use cases, services, transformers, formatters, or interfaces.');

    // Repository implementations must end with "Repository"
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\Database'))
        ->should(new HaveNameMatching('*Repository'))
        ->because('Repository implementations should have a "Repository" suffix.');

    // API clients must end with "Client"
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\Api'))
        ->should(new HaveNameMatching('*Client'))
        ->because('API client classes should have a "Client" suffix.');

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
        ->because('Public interfaces belong in Domain or Application layers. Infrastructure implements contracts defined by higher layers.');

    // RULE 6: Interfaces must be in Contracts subdirectories
    //
    // WHY: Enforces consistent organization and makes contracts easy to discover.
    //
    // CORRECT:
    // ✅ namespace App\Domain\Contracts;
    //    interface OrderRepositoryInterface { }
    //    namespace App\Application\Contracts;
    //    interface MixpanelClientInterface { }
    //
    $rules[] = Rule::allClasses()
        ->that(new HaveNameMatching('*Interface'))
        ->should(new ResideInOneOfTheseNamespaces(
            'App\Domain\Contracts',
            'App\Application\Contracts',
        ))
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
        ->that(new ResideInOneOfTheseNamespaces(
            'App\Domain\Contracts',
            'App\Application\Contracts',
        ))
        ->should(new HaveNameMatching('*Interface'))
        ->because('Contracts directories should only contain interfaces, not implementations.');

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
