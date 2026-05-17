<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionAnnotationRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Infrastructure\HelpScout\Dispatchers\QueuedContactFormDispatcher;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionActionRepository;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionRepository;
use App\Infrastructure\Marketing\Repositories\EloquentContactSubmissionAnnotationRepository;
use App\Infrastructure\Marketing\Repositories\EloquentContactSubmissionDashboardQueryRepository;
use App\Infrastructure\Validation\EmailValidationService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Contact Submission Service Provider.
 *
 * Deferred provider for contact form submission handling.
 * Binds repositories and the HelpScout conversation write client.
 */
final class ContactSubmissionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerDispatchers();
        $this->registerRepositories();
        $this->registerServices();
    }

    private function registerDispatchers(): void
    {
        $this->app->singleton(ContactFormDispatcherInterface::class, QueuedContactFormDispatcher::class);
    }

    private function registerRepositories(): void
    {
        $this->registerWriteRepositories();
        $this->registerDashboardRepositories();
    }

    private function registerWriteRepositories(): void
    {
        $this->app->singleton(
            ContactSubmissionRepositoryInterface::class,
            EloquentContactSubmissionRepository::class,
        );

        $this->app->singleton(
            ContactSubmissionActionRepositoryInterface::class,
            EloquentContactSubmissionActionRepository::class,
        );
    }

    private function registerDashboardRepositories(): void
    {
        $this->app->singleton(
            ContactSubmissionDashboardQueryRepositoryInterface::class,
            EloquentContactSubmissionDashboardQueryRepository::class,
        );

        $this->app->singleton(
            ContactSubmissionAnnotationRepositoryInterface::class,
            EloquentContactSubmissionAnnotationRepository::class,
        );
    }

    private function registerServices(): void
    {
        $this->app->singleton(
            ConversationWriteClientInterface::class,
            static fn(): ConversationWriteClientInterface => HelpScoutClientFactory::createConversationWriteClient(),
        );

        $this->app->singleton(
            EmailValidationServiceInterface::class,
            EmailValidationService::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ContactFormDispatcherInterface::class,
            ContactSubmissionRepositoryInterface::class,
            ContactSubmissionActionRepositoryInterface::class,
            ContactSubmissionDashboardQueryRepositoryInterface::class,
            ContactSubmissionAnnotationRepositoryInterface::class,
            ConversationWriteClientInterface::class,
            EmailValidationServiceInterface::class,
        ];
    }
}
