<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\ContactSubmission\UseCases\ProcessContactSubmissionUseCase;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Application\Contracts\EmailValidationServiceInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionActionRepository;
use App\Infrastructure\Ingest\ContactSubmission\Repositories\EloquentContactSubmissionRepository;
use App\Infrastructure\Validation\EmailValidationService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Contact Submission Service Provider.
 *
 * Deferred provider for contact form submission handling.
 * Binds repositories and the HelpScout conversation write client.
 *
 * Note: ProcessContactSubmissionUseCase is auto-resolved by Laravel's container
 * since all its dependencies are type-hinted (HelpScoutSystemUserId is bound
 * in HelpScoutServiceProvider with fail-fast validation).
 */
final class ContactSubmissionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        // Repository bindings
        $this->app->singleton(
            ContactSubmissionRepositoryInterface::class,
            EloquentContactSubmissionRepository::class,
        );

        $this->app->singleton(
            ContactSubmissionActionRepositoryInterface::class,
            EloquentContactSubmissionActionRepository::class,
        );

        // HelpScout write client for conversation creation
        $this->app->singleton(
            ConversationWriteClientInterface::class,
            static fn(): ConversationWriteClientInterface => HelpScoutClientFactory::createConversationWriteClient(),
        );

        // Email validation service
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
            ContactSubmissionRepositoryInterface::class,
            ContactSubmissionActionRepositoryInterface::class,
            ConversationWriteClientInterface::class,
            EmailValidationServiceInterface::class,
            ProcessContactSubmissionUseCase::class,
        ];
    }
}
